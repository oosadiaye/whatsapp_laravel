<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GoogleConnection;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleCalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The first read of a freshly connected Google Calendar. Done on the queue so a big calendar never ties up (or times
 * out) the web request that brought the person back from Google.
 */
class ImportGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public readonly int $connectionId) {}

    public function handle(GoogleCalendarSync $sync): void
    {
        $connection = GoogleConnection::find($this->connectionId);
        if ($connection === null || ! $connection->isUsable()) {
            return;
        }

        try {
            $sync->import($connection);
        } catch (GoogleApiException $e) {
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 300, '')])->save();
        }
    }
}
