<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:dispatch-scheduled')->everyMinute();

// Pull fresh template state from Meta so PENDING → APPROVED transitions
// surface in the UI without manual re-syncing. Cheap call, well below
// Meta's 200/hour rate limit per WABA.
Schedule::command('templates:sync-status')->everyFifteenMinutes()->withoutOverlapping();
