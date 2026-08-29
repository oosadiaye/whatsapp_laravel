<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// onOneServer() on the mutating schedules is defense-in-depth for a multi-host
// deployment: withoutOverlapping() only guards same-host overlap, so without it
// two scheduler hosts could both fire a due dispatch on the same minute. The
// campaign/email double-send paths also have atomic claims, but the flag keeps
// every mutating tick single-fire.
Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();

// Automated email schedules: launch due scheduled email campaigns + re-arm
// recurring ones (daily/weekly/monthly).
Schedule::command('email:dispatch-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();

// Pull fresh template state from Meta so PENDING → APPROVED transitions
// surface in the UI without manual re-syncing. Cheap call, well below
// Meta's 200/hour rate limit per WABA.
Schedule::command('templates:sync-status')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Cleanup stale calls stuck in-flight (webhook never arrived). Threshold 30
// minutes. Fires everyMinute so stuck banners clear within ~1 min.
Schedule::command('calls:cleanup-stale')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Call Workspace: delete recording audio past its retention window (no-op when
// voice.recording_retention_days = 0). Daily is fine — retention is coarse.
Schedule::command('calls:prune-recordings')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Snapshot Horizon queue metrics so the dashboard's wait-time/throughput trends
// have history to draw (audit L9). No-op when Horizon isn't running.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Alert when a queue backs up past a healthy threshold (audit L10). Fires
// QueueBusy → logged in AppServiceProvider. Bare queue names use the default
// connection, so this is queue-driver agnostic (redis or database).
Schedule::command('queue:monitor messages,imports,default --max=200')->everyFiveMinutes();

// Email sequences: advance recipients whose delay has elapsed, sending the
// next step in the sequence + scheduling the following.
Schedule::command('email-sequences:process')->everyMinute()->withoutOverlapping()->onOneServer();

// Email warmup: send warmup emails from configured accounts to keep sender
// reputation healthy.
Schedule::command('email:warmup')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Lead scoring: recalculate contact lead scores from email engagement.
Schedule::command('email:score-leads')->hourly()->withoutOverlapping()->onOneServer();

// Auto-away: mark agents as away after 5 minutes of inactivity (RealtimePulse
// touches last_seen_at every 30s). Keeps the RoundRobinAssigner from routing
// new inbound calls to idle agents.
Schedule::command('agents:auto-away --threshold=5')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Inbound email-client sync (plan B3). Only runs while the feature is enabled;
// interval from config (clamped to a valid */N cron). Each account syncs on the
// mail-sync queue.
$mailSyncEvery = min(59, max(1, (int) config('mail_client.sync_interval_minutes', 2)));
Schedule::command('mailbox:sync')
    ->cron("*/{$mailSyncEvery} * * * *")
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (bool) config('mail_client.enabled'));
