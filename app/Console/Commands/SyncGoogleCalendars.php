<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GoogleConnection;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleCalendarSync;
use App\Services\Google\GoogleConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Pulls every connected person's Google Calendar into BlastIQ. A failing account never stops the others. */
class SyncGoogleCalendars extends Command
{
    protected $signature = 'google:sync';

    protected $description = 'Import connected Google calendars onto the Meetings calendar';

    public function handle(GoogleCalendarSync $sync): int
    {
        if (! GoogleConfig::isConfigured()) {
            return self::SUCCESS;
        }

        GoogleConnection::query()
            ->where('status', GoogleConnection::STATUS_ACTIVE)
            ->each(function (GoogleConnection $connection) use ($sync): void {
                try {
                    $sync->import($connection);
                } catch (GoogleApiException $e) {
                    $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 300, '')])->save();
                    $this->warn("Connection {$connection->id}: {$e->getMessage()}");
                } catch (\Throwable $e) {
                    report($e);
                    $connection->forceFill(['last_error' => 'Google could not be reached. Will try again shortly.'])->save();
                }
            });

        return self::SUCCESS;
    }
}
