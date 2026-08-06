<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Services\EmailCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Drives automated email schedules. Every minute it:
 *   1. re-arms recurring campaigns that finished a run (compute the next
 *      scheduled_at from the recurrence, reset per-run counters), and
 *   2. launches any scheduled campaign whose time has arrived.
 *
 * Mirrors campaigns:dispatch-scheduled for WhatsApp.
 */
class DispatchScheduledEmailCampaigns extends Command
{
    protected $signature = 'email:dispatch-scheduled';

    protected $description = 'Launch due scheduled email campaigns and re-arm recurring ones';

    public function handle(EmailCampaignService $service): int
    {
        // 1. Re-arm finished recurring campaigns for their next run. A failed run
        //    still re-arms — a transient bad run shouldn't kill the schedule.
        EmailCampaign::query()
            ->whereIn('status', [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_FAILED])
            ->where('recurrence', '!=', EmailCampaign::RECURRENCE_NONE)
            ->whereNotNull('last_run_at')
            ->get()
            ->each(fn (EmailCampaign $c) => $this->rearm($c));

        // 2. Launch scheduled campaigns whose time has come.
        $due = EmailCampaign::query()
            ->where('status', EmailCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $service->launch($campaign);
        }

        $this->info("Launched {$due->count()} scheduled email campaign(s).");

        return self::SUCCESS;
    }

    private function rearm(EmailCampaign $campaign): void
    {
        $next = $this->nextRun($campaign);

        if ($next === null) {
            return; // non-recurring or past its recurrence_until — leave as sent
        }

        // A recurring run is a FRESH send to the full audience, not a resume of
        // the previous one. Clear the prior run's EmailLogs so:
        //   - EmailCampaignDispatch's "relaunch safety" (skip anyone with an
        //     existing log) doesn't produce an empty audience — without this a
        //     recurring campaign only ever sent once, and
        //   - total_recipients reflects THIS run, not all-time history.
        EmailLog::where('email_campaign_id', $campaign->id)->delete();

        $campaign->update([
            'status' => EmailCampaign::STATUS_SCHEDULED,
            'scheduled_at' => $next,
            'completed_at' => null,
            'total_recipients' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'opened_count' => 0,
        ]);
    }

    private function nextRun(EmailCampaign $campaign): ?Carbon
    {
        $base = ($campaign->last_run_at ?? now())->copy();

        $next = match ($campaign->recurrence) {
            EmailCampaign::RECURRENCE_DAILY => $base->addDay(),
            EmailCampaign::RECURRENCE_WEEKLY => $base->addWeek(),
            EmailCampaign::RECURRENCE_MONTHLY => $base->addMonth(),
            default => null,
        };

        if ($next === null) {
            return null;
        }

        if ($campaign->recurrence_until !== null && $next->greaterThan($campaign->recurrence_until)) {
            return null; // schedule has ended
        }

        return $next;
    }
}
