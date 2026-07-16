<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

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

        $imported = 0;
        $duplicates = 0;
        $invalid = 0;

        // Stream the file row-by-row (audit M6 — never materialise the whole
        // file) and process in transactional chunks (audit L3 — a mid-chunk
        // failure rolls back instead of leaving partial state).
        $this->rows($filePath)->chunk(100)->each(function ($chunk) use (
            &$imported, &$duplicates, &$invalid, $columnMap, $userId, $group, $defaultCountryCode
        ): void {
            DB::transaction(function () use (
                $chunk, &$imported, &$duplicates, &$invalid, $columnMap, $userId, $group, $defaultCountryCode
            ): void {
                foreach ($chunk as $row) {
                    $rawPhone = isset($columnMap['phone']) ? ($row[$columnMap['phone']] ?? '') : '';
                    $phone = $this->normalizePhone((string) $rawPhone, $defaultCountryCode);

                    $rawEmail = isset($columnMap['email']) ? trim((string) ($row[$columnMap['email']] ?? '')) : '';
                    $email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? strtolower($rawEmail) : null;

                    // A row needs at least a valid phone OR a valid email to be a
                    // contact (email-only prospects are allowed now).
                    if ($phone === null && $email === null) {
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

                    // Only write name/custom_fields when the incoming row actually
                    // carries a value. Blank cells (a phone-only re-import) must NOT
                    // overwrite a contact's existing name/custom fields with ''/null
                    // — that was silent data loss. Omitting the key leaves the stored
                    // value untouched on update, and falls back to the column default
                    // on create.
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

                    // IncludingTrashed: re-importing a previously-deleted contact
                    // revives + updates it instead of dying on the unversioned
                    // unique index mid-batch (see Contact::firstOrNewIncludingTrashed).
                    $contact = Contact::updateOrCreateIncludingTrashed($key, $attributes);

                    if ($contact->wasRecentlyCreated) {
                        $imported++;
                    } else {
                        $duplicates++;
                    }

                    if (! $group->contacts()->where('contact_id', $contact->id)->exists()) {
                        $group->contacts()->attach($contact->id);
                    }
                }
            });
        });

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
     * A lazy row stream for the import file, uniform across formats. CSV is read
     * row-by-row (true streaming — audit M6); XLSX is materialised by
     * Maatwebsite\Excel::toArray and wrapped so callers get the same lazy
     * interface (chunk-reading XLSX is a larger change, deferred).
     *
     * @return LazyCollection<int, array<string, string>>
     */
    private function rows(string $filePath): LazyCollection
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return LazyCollection::make(fn () => $this->streamCsv($filePath));
        }

        return LazyCollection::make($this->readXlsx($filePath));
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

    /**
     * Read rows from an XLSX file using Maatwebsite\Excel.
     *
     * @return array<int, array<string, string>>
     */
    private function readXlsx(string $filePath): array
    {
        // Same path-resolution rule as readCsv — Maatwebsite\Excel::toArray
        // needs an absolute filesystem path, not a Storage disk key.
        $filePath = $this->absoluteCsvPath($filePath);
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
