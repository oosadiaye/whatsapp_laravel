<?php

declare(strict_types=1);

namespace App\Imports;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * Streams an .xlsx contact import in bounded chunks (audit M6) so a large sheet
 * never loads fully into memory — the workbook analogue of the CSV generator in
 * {@see \App\Services\ContactImportService::streamCsv()}. PhpSpreadsheet reads
 * `chunkSize()` rows at a time and calls {@see collection()} per chunk.
 *
 * The header row is captured from the first row of the file; each subsequent row
 * is combined with the headers and handed to the shared row handler (the same
 * `processImportRow` the CSV path uses), which returns the counter key the row
 * belongs to. Kept off `WithHeadingRow` on purpose: the posted column map keys
 * on the raw header text, and the heading-row formatter would snake_case it.
 */
class ContactsSheetImport implements ToCollection, WithChunkReading
{
    public int $imported = 0;

    public int $duplicates = 0;

    public int $invalid = 0;

    /** @var array<int, string>|null */
    private ?array $headers = null;

    /**
     * @param  Closure(array<string, string>): string  $rowHandler  returns
     *         'imported'|'duplicates'|'invalid' for the given associative row
     */
    public function __construct(private readonly Closure $rowHandler)
    {
    }

    /**
     * @param  Collection<int, Collection<int, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        // One transaction per chunk (audit L3): a mid-chunk failure rolls back
        // rather than leaving a partially-imported sheet.
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $rawCells) {
                $cells = array_map(
                    static fn ($value): string => $value === null ? '' : (string) $value,
                    $rawCells instanceof Collection ? $rawCells->all() : (array) $rawCells,
                );

                // The first row of the whole file is the header row.
                if ($this->headers === null) {
                    $this->headers = array_map('trim', $cells);

                    continue;
                }

                // Ragged row (fewer/more cells than headers) — skip, matching the
                // CSV reader's count guard.
                if (count($cells) !== count($this->headers)) {
                    continue;
                }

                $status = ($this->rowHandler)(array_combine($this->headers, $cells));

                match ($status) {
                    'imported' => $this->imported++,
                    'duplicates' => $this->duplicates++,
                    default => $this->invalid++,
                };
            }
        });
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
