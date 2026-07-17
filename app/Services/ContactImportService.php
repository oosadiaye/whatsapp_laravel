<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\ContactsSheetImport;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Maatwebsite\Excel\Facades\Excel;

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
        $group = ContactGroup::findOrFail($groupId);

        // Resolve the default country code once per import rather than once per
        // row (audit M6 — normalizePhone would otherwise hit Setting::get() for
        // every row).
        $defaultCountryCode = (string) Setting::get('default_country_code', '234');

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $counters = $extension === 'csv'
            ? $this->importCsv($filePath, $columnMap, $userId, $group, $defaultCountryCode)
            : $this->importXlsx($filePath, $columnMap, $userId, $group, $defaultCountryCode);

        $group->update(['contact_count' => $group->contacts()->count()]);

        return $counters;
    }

    /**
     * Stream a CSV row-by-row (audit M6 — never materialise the whole file) and
     * process it in transactional chunks (audit L3 — a mid-chunk failure rolls
     * back instead of leaving partial state).
     *
     * @param  array<string, string>  $columnMap
     * @return array{imported: int, duplicates: int, invalid: int}
     */
    private function importCsv(string $filePath, array $columnMap, int $userId, ContactGroup $group, string $defaultCountryCode): array
    {
        $counters = ['imported' => 0, 'duplicates' => 0, 'invalid' => 0];

        LazyCollection::make(fn () => $this->streamCsv($filePath))
            ->chunk(100)
            ->each(function ($chunk) use (&$counters, $columnMap, $userId, $group, $defaultCountryCode): void {
                DB::transaction(function () use ($chunk, &$counters, $columnMap, $userId, $group, $defaultCountryCode): void {
                    foreach ($chunk as $row) {
                        $counters[$this->processImportRow($row, $columnMap, $userId, $group, $defaultCountryCode)]++;
                    }
                });
            });

        return $counters;
    }

    /**
     * Chunk-read an .xlsx workbook (audit M6) via {@see ContactsSheetImport} so a
     * large sheet never loads fully into memory, mirroring the CSV stream. Rows
     * flow through the same {@see processImportRow()} handler the CSV path uses,
     * so the two formats can't drift.
     *
     * @param  array<string, string>  $columnMap
     * @return array{imported: int, duplicates: int, invalid: int}
     */
    private function importXlsx(string $filePath, array $columnMap, int $userId, ContactGroup $group, string $defaultCountryCode): array
    {
        $import = new ContactsSheetImport(
            fn (array $row): string => $this->processImportRow($row, $columnMap, $userId, $group, $defaultCountryCode),
        );

        Excel::import($import, $this->absoluteCsvPath($filePath));

        return [
            'imported' => $import->imported,
            'duplicates' => $import->duplicates,
            'invalid' => $import->invalid,
        ];
    }

    /**
     * Process a single import row: parse phone/email, upsert the contact
     * (reviving a soft-deleted match — see Contact::firstOrNewIncludingTrashed),
     * and attach it to the group. Shared by the CSV and XLSX readers.
     *
     * @param  array<string, mixed>  $row  associative, keyed by the file's headers
     * @param  array<string, string>  $columnMap
     * @return string  the result key this row increments: imported|duplicates|invalid
     */
    private function processImportRow(array $row, array $columnMap, int $userId, ContactGroup $group, string $defaultCountryCode): string
    {
        $rawPhone = isset($columnMap['phone']) ? ($row[$columnMap['phone']] ?? '') : '';
        $phone = $this->normalizePhone((string) $rawPhone, $defaultCountryCode);

        $rawEmail = isset($columnMap['email']) ? trim((string) ($row[$columnMap['email']] ?? '')) : '';
        $email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? strtolower($rawEmail) : null;

        // A row needs at least a valid phone OR a valid email to be a contact
        // (email-only prospects are allowed now).
        if ($phone === null && $email === null) {
            return 'invalid';
        }

        $name = $row[$columnMap['name']] ?? null;

        $customFields = [];
        if (isset($columnMap['custom_field_1'], $row[$columnMap['custom_field_1']])) {
            $customFields['custom_field_1'] = $row[$columnMap['custom_field_1']];
        }
        if (isset($columnMap['custom_field_2'], $row[$columnMap['custom_field_2']])) {
            $customFields['custom_field_2'] = $row[$columnMap['custom_field_2']];
        }

        // Only write name/custom_fields when the incoming row actually carries a
        // value. Blank cells (a phone-only re-import) must NOT overwrite an
        // existing name/custom fields with ''/null — that was silent data loss.
        $attributes = [];
        if ($name !== null && trim((string) $name) !== '') {
            $attributes['name'] = $name;
        }
        if ($customFields !== []) {
            $attributes['custom_fields'] = $customFields;
        }
        if ($email !== null) {
            $attributes['email'] = $email;
        }

        // Upsert on phone when present, else on email (email-only rows).
        $key = $phone !== null
            ? ['user_id' => $userId, 'phone' => $phone]
            : ['user_id' => $userId, 'email' => $email];

        $contact = Contact::updateOrCreateIncludingTrashed($key, $attributes);

        if (! $group->contacts()->where('contact_id', $contact->id)->exists()) {
            $group->contacts()->attach($contact->id);
        }

        return $contact->wasRecentlyCreated ? 'imported' : 'duplicates';
    }

    /**
     * Normalize a raw phone number string into a clean international format.
     *
     * When the input is a local number (leading 0 or missing a country code),
     * the configured `default_country_code` setting is prepended so the stored
     * value is full E.164. This keeps send-time behaviour consistent across
     * orgs: WhatsAppCloudApiService only strips non-digits and assumes the
     * stored phone is already E.164, so the country code must be applied here
     * at import time, not hardcoded to Nigeria.
     *
     * @return string|null  Normalized phone number or null if invalid
     */
    public function normalizePhone(string $raw, ?string $defaultCountryCode = null): ?string
    {
        $defaultCountryCode ??= (string) Setting::get('default_country_code', '234');

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
     * Replace named personalization tokens in a freeform message with contact
     * data. Delegates to the shared {@see \App\Support\Personalizer} so the field
     * resolution matches the WhatsApp-template path. Kept as a method for
     * backward-compatible call sites.
     */
    public function personalizeMessage(string $template, Contact $contact, string $campaignName = ''): string
    {
        return (new \App\Support\Personalizer())->named($contact, $template, $campaignName);
    }

    /**
     * Stream rows from a CSV file one at a time (audit M6) instead of building
     * the whole array in memory.
     *
     * @param  string  $filePath  Either a Storage disk key like "imports/abc.csv"
     *                            (returned by $request->file()->store('imports'))
     *                            OR an absolute filesystem path. See
     *                            {@see absoluteCsvPath()} for why resolution is
     *                            needed (queued workers run from the project root).
     * @return \Generator<int, array<string, string>>
     */
    private function streamCsv(string $filePath): \Generator
    {
        $absolutePath = $this->absoluteCsvPath($filePath);
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            Log::error('ContactImportService: Failed to open CSV file', [
                'path' => $filePath,
                'resolved_to' => $absolutePath,
            ]);

            return;
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                return;
            }

            // Strip the UTF-8 BOM Excel (and our own downloadTemplate) prepends
            // to the first cell — otherwise the first header key becomes
            // "\xEF\xBB\xBFphone" and never matches the posted column map.
            if (isset($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
                $headers[0] = substr($headers[0], 3);
            }
            $headers = array_map('trim', $headers);

            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === count($headers)) {
                    yield array_combine($headers, $line);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Resolve a stored-file reference to an absolute filesystem path.
     *
     * The controller hands us $request->file()->store('imports'), which
     * returns disk-key strings like "imports/abc.csv". Laravel's Storage
     * facade knows that means storage/app/imports/abc.csv. fopen() and
     * Maatwebsite\Excel::toArray() both need the absolute filesystem path.
     *
     * If the caller already passed an absolute path (starts with / or
     * Windows drive letter), pass it through unchanged.
     *
     * Why Storage::disk('local')->path() and not storage_path('app/...'):
     * tests use Storage::fake('local') which remounts the disk to a temp
     * directory. The disk's own path() method returns the *current*
     * disk root, which is correct in both prod (real storage/app) and
     * tests (the fake temp dir). storage_path() always returns the real
     * production path and would break the test.
     */
    private function absoluteCsvPath(string $filePath): string
    {
        // Already absolute? Use as-is.
        // Linux/Mac:  /foo/bar
        // Windows:    C:\foo or C:/foo
        if (str_starts_with($filePath, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $filePath)) {
            return $filePath;
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->path($filePath);
    }
}
