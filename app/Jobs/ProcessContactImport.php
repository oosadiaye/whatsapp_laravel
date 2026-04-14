<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ContactImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessContactImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $filePath,
        public readonly int $groupId,
        public readonly array $columnMap,
        public readonly int $userId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $service = new ContactImportService();

        $result = $service->importFromFile(
            $this->filePath,
            $this->groupId,
            $this->columnMap,
            $this->userId,
        );

        Log::info('ProcessContactImport completed', [
            'group_id' => $this->groupId,
            'user_id' => $this->userId,
            'imported' => $result['imported'],
            'duplicates' => $result['duplicates'],
            'invalid' => $result['invalid'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessContactImport failed', [
            'group_id' => $this->groupId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
