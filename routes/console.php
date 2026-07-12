<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping();

// Pull fresh template state from Meta so PENDING → APPROVED transitions
// surface in the UI without manual re-syncing. Cheap call, well below
// Meta's 200/hour rate limit per WABA.
Schedule::command('templates:sync-status')->everyFifteenMinutes()->withoutOverlapping();

// Phase 17: Cleanup stale calls stuck in ringing/connected (webhook never arrived).
// Threshold 30 minutes. Fires everyMinute so stuck banners clear within ~1 min.
Schedule::command('calls:cleanup-stale')
    ->everyMinute()
    ->withoutOverlapping();

// Call Workspace: delete recording audio past its retention window (no-op when
// voice.recording_retention_days = 0). Daily is fine — retention is coarse.
Schedule::command('calls:prune-recordings')
    ->daily()
    ->withoutOverlapping();
