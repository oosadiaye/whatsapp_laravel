<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CampaignBatchDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Exactly one attempt: a retry would re-enter handle() and, past the
     * QUEUED guard (status is now RUNNING), re-run failed() — the audience is
     * fanned out once. Recovery is an operator relaunch, not an automatic retry
     * (audit M10).
     */
    public int $tries = 1;

    /**
     * Fan-out streams the audience and bulk-inserts logs, so it stays well
     * under this even for large campaigns — but set it explicitly so a
     * pathological run fails cleanly instead of being silently killed
     * (audit M1). Must stay below the queue retry_after (360s).
     */
    public int $timeout = 300;

    public function __construct(
        public Campaign $campaign,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Atomic claim: only ONE worker may transition this campaign QUEUED→RUNNING.
        // The previous check-then-act (fresh() → status check → unconditional
        // update) let two concurrent batch jobs — produced by the scheduler
        // re-tick on a backlogged queue, or a double-clicked launch — both pass
        // the guard, both fan out the full audience, and double-send every
        // (billable) message. Mirrors EmailCampaignDispatch's proven claim.
        $claimed = Campaign::query()
            ->whereKey($this->campaign->id)
            ->where('status', 'QUEUED')
            ->update([
                'status' => 'RUNNING',
                'started_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            // Cancelled/paused, or already claimed by a concurrent worker — do
            // not resurrect it as RUNNING or fan out a second time.
            return;
        }

        $this->campaign = $this->campaign->fresh();

        // Audience: contacts in any of the campaign's groups, deduped. Resolved
        // as a reusable query (not a materialized ->get()) so we can STREAM it in
        // chunks — a low-tens-of-thousands campaign never loads its whole audience
        // into memory at once (audit M1).
        $groupIds = $this->campaign->contactGroups->pluck('id');
        $audience = fn () => Contact::query()
            ->active()
            ->whereIn('id', function ($q) use ($groupIds) {
                $q->select('contact_id')
                    ->from('contact_group')
                    ->whereIn('group_id', $groupIds);
            });

        $intervalMs = (60 / $this->campaign->rate_per_minute) * 1000;
        $delay = 0.0;

        // Step 1 — INSERT (no dispatch): create a PENDING log for every contact
        // that doesn't already have one for this campaign. The unique
        // (campaign_id, contact_id) index means a relaunch never double-inserts.
        $audience()
            ->whereNotIn('id', function ($q) {
                $q->select('contact_id')
                    ->from('message_logs')
                    ->where('campaign_id', $this->campaign->id);
            })
            ->chunkById(500, function ($contacts): void {
                $this->insertPendingLogs($contacts);
            });

        // Step 2 — DISPATCH: fan a paced send out for EVERY still-PENDING log of
        // this campaign, not just the rows we inserted above. This closes the
        // silent-loss gap: previously insert+dispatch were interleaved per chunk,
        // so a crash/restart/OOM mid-fan-out left contacts with a PENDING log but
        // no send job — and the relaunch's "skip already-logged contacts" dedupe
        // then excluded them forever. Driving all PENDING logs means a relaunch
        // re-drives exactly those orphans. It cannot double-send: SendWhatsAppMessage
        // refreshes its log and bails when it is no longer PENDING (handle()), so a
        // second dispatch for a log that already has an in-flight/finished job is a
        // no-op.
        MessageLog::query()
            ->where('campaign_id', $this->campaign->id)
            ->where('status', 'PENDING')
            ->with('contact')
            ->chunkById(500, function ($logs) use (&$delay, $intervalMs): void {
                foreach ($logs as $log) {
                    $contact = $log->contact;
                    if ($contact === null) {
                        // Contact soft-deleted since enrolment — never message it.
                        continue;
                    }

                    $jitter = rand(
                        (int) ($this->campaign->delay_min * 1000),
                        (int) ($this->campaign->delay_max * 1000),
                    );
                    $delay += $intervalMs + $jitter;

                    SendWhatsAppMessage::dispatch($log, $this->campaign, $contact)
                        ->delay(now()->addMilliseconds((int) $delay))
                        ->onQueue('messages');
                }
            });

        // total_contacts = the ACTUAL number of logs for this campaign — the
        // definitive completion denominator. Deriving it from a count() taken
        // BEFORE the loop drifted from what was dispatched if group membership
        // changed mid-run, leaving the campaign stuck RUNNING (review #4). Counting
        // the logs afterwards can't drift, and is correct across a resumed relaunch.
        $total = MessageLog::where('campaign_id', $this->campaign->id)->count();
        $this->campaign->update(['total_contacts' => $total]);

        if ($total === 0) {
            $this->campaign->update([
                'status' => 'COMPLETED',
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        // A relaunch that dispatched no NEW sends (every contact already logged +
        // resolved) won't trigger completion via a send job — reconcile now.
        // On a fresh launch the logs are still PENDING, so this is a no-op.
        (new CampaignService)->checkCompletion($this->campaign);
    }

    /**
     * Bulk-insert the PENDING logs for one chunk in a single statement (instead
     * of one INSERT per contact). Dispatch is intentionally decoupled — see the
     * two-step comment in handle() — so a crash between insert and dispatch is
     * recoverable by a relaunch rather than silently dropping recipients.
     *
     * @param  Collection<int, Contact>  $contacts
     */
    private function insertPendingLogs($contacts): void
    {
        $now = Carbon::now();

        MessageLog::insert($contacts->map(fn (Contact $c) => [
            'campaign_id' => $this->campaign->id,
            'contact_id' => $c->id,
            'phone' => $c->phone,
            'status' => 'PENDING',
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * A crash mid-fan-out (e.g. a DB timeout on a large contact list) would
     * otherwise leave the campaign stuck in RUNNING with only a partial
     * audience dispatched and no operator signal. Mark it FAILED so it is
     * visible on the campaigns list and can be investigated or relaunched.
     */
    public function failed(\Throwable $e): void
    {
        $this->campaign->fresh()?->update([
            'status' => 'FAILED',
            'completed_at' => Carbon::now(),
        ]);

        Log::error('CampaignBatchDispatch failed', [
            'campaign_id' => $this->campaign->id,
            'error' => $e->getMessage(),
        ]);
    }
}
