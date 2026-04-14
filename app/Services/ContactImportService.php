<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Support\Facades\Log;

/**
 * Handles contact import from CSV/XLSX files and message personalization.
 */
class ContactImportService
{
    /**
     * Import contacts from a CSV or XLSX file into a contact group.
     *
     * @param  array<string, string>  $columnMap  Keys: 'phone', 'name', optional 'custom_field_1', 'custom_field_2'
     * @return array{imported: int, duplicates: int, invalid: int}
     */
    public function importFromFile(string $filePath, int $groupId, array $columnMap, int $userId): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = $extension === 'csv'
            ? $this->readCsv($filePath)
            : $this->readXlsx($filePath);

        $group = ContactGroup::findOrFail($groupId);

        $imported = 0;
        $duplicates = 0;
        $invalid = 0;

        $chunks = array_chunk($rows, 100);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $row) {
                $rawPhone = $row[$columnMap['phone']] ?? '';
                $phone = $this->normalizePhone((string) $rawPhone);

                if ($phone === null) {
                    $invalid++;
                    continue;
                }

                $name = $row[$columnMap['name']] ?? null;

                $customFields = [];
                if (isset($columnMap['custom_field_1'], $row[$columnMap['custom_field_1']])) {
                    $customFields['custom_field_1'] = $row[$columnMap['custom_field_1']];
                }
                if (isset($columnMap['custom_field_2'], $row[$columnMap['custom_field_2']])) {
                    $customFields['custom_field_2'] = $row[$columnMap['custom_field_2']];
                }

                $contact = Contact::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'phone' => $phone,
                    ],
                    [
                        'name' => $name,
                        'custom_fields' => $customFields ?: null,
                    ],
                );

                if ($contact->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $duplicates++;
                }

                if (! $group->contacts()->where('contact_id', $contact->id)->exists()) {
                    $group->contacts()->attach($contact->id);
                }
            }
        }

        $group->update(['contact_count' => $group->contacts()->count()]);

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }

    /**
     * Normalize a raw phone number string into a clean international format.
     *
     * @return string|null  Normalized phone number or null if invalid
     */
    public function normalizePhone(string $raw, string $defaultCountryCode = '234'): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', $raw);

        if ($phone === '' || $phone === null) {
            return null;
        }

        if (str_starts_with($phone, '0') && strlen($phone) === 11) {
            $phone = $defaultCountryCode . substr($phone, 1);
        } elseif (! str_starts_with($phone, '1') && ! str_starts_with($phone, '2') &&
                  ! str_starts_with($phone, '3') && ! str_starts_with($phone, '4') &&
                  ! str_starts_with($phone, '5') && ! str_starts_with($phone, '6') &&
                  ! str_starts_with($phone, '7') && ! str_starts_with($phone, '8') &&
                  ! str_starts_with($phone, '9')) {
            return null;
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * Replace personalization tokens in a message template with contact data.
     */
    public function personalizeMessage(string $template, Contact $contact, string $campaignName = ''): string
    {
        $name = $contact->name ?? 'Friend';
        $firstName = explode(' ', $name)[0];
        $customFields = $contact->custom_fields ?? [];

        $replacements = [
            '{{name}}' => $name,
            '{{phone}}' => $contact->phone,
            '{{first_name}}' => $firstName,
            '{{custom_field_1}}' => $customFields['custom_field_1'] ?? '',
            '{{custom_field_2}}' => $customFields['custom_field_2'] ?? '',
            '{{date}}' => now()->format('d F Y'),
            '{{campaign_name}}' => $campaignName,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Read rows from a CSV file.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            Log::error('ContactImportService: Failed to open CSV file', ['path' => $filePath]);

            return [];
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map('trim', $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, $line);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Read rows from an XLSX file using Maatwebsite\Excel.
     *
     * @return array<int, array<string, string>>
     */
    private function readXlsx(string $filePath): array
    {
        $rows = [];

        try {
            $data = \Maatwebsite\Excel\Facades\Excel::toArray(null, $filePath);

            if (empty($data) || empty($data[0])) {
                return [];
            }

            $sheet = $data[0];
            $headers = array_map('trim', $sheet[0]);

            for ($i = 1, $count = count($sheet); $i < $count; $i++) {
                if (count($sheet[$i]) === count($headers)) {
                    $rows[] = array_combine($headers, $sheet[$i]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ContactImportService: Failed to read XLSX file', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return $rows;
    }
}
