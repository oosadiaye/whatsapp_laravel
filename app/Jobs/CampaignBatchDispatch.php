<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
        $this->campaign = $this->campaign->fresh();

        // The campaign may have been cancelled (or otherwise moved off QUEUED)
        // between launch() dispatching this job and the worker picking it up.
        // Bail rather than resurrecting it as RUNNING and fanning out sends.
        if ($this->campaign->status !== 'QUEUED') {
            return;
        }

        $this->campaign->update([
            'status' => 'RUNNING',
            'started_at' => Carbon::now(),
        ]);

        // Audience: contacts in any of the campaign's groups, deduped. Resolved
        // as a reusable query (not a materialized ->get()) so we can count it and
        // then STREAM it in chunks — a low-tens-of-thousands campaign never loads
        // its whole audience into memory at once (audit M1).
        $groupIds = $this->campaign->contactGroups->pluck('id');
        $audience = fn () => Contact::query()
            ->active()
            ->whereIn('id', function ($q) use ($groupIds) {
                $q->select('contact_id')
                    ->from('contact_group')
                    ->whereIn('group_id', $groupIds);
            });

        $total = $audience()->count();
        $this->campaign->update(['total_contacts' => $total]);

        if ($total === 0) {
            $this->campaign->update([
                'status' => 'COMPLETED',
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        $intervalMs = (60 / $this->campaign->rate_per_minute) * 1000;
        $delay = 0.0;

        $audience()->chunkById(500, function ($contacts) use (&$delay, $intervalMs): void {
            $this->fanOutChunk($contacts, $delay, $intervalMs);
        });
    }

    /**
     * Bulk-insert the PENDING logs for one chunk in a single statement (instead
     * of one INSERT per contact), then dispatch a spaced send per contact. The
     * running $delay accumulator is threaded by reference so pacing is
     * continuous across chunks.
     *
     * @param  \Illuminate\Support\Collection<int, Contact>  $contacts
     */
    private function fanOutChunk($contacts, float &$delay, float $intervalMs): void
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

        // Re-read the rows we just created to get their ids. Each contact appears
        // once in the audience, so keying by contact_id is unambiguous.
        $logs = MessageLog::query()
            ->where('campaign_id', $this->campaign->id)
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->where('status', 'PENDING')
            ->get()
            ->keyBy('contact_id');

        foreach ($contacts as $contact) {
            $log = $logs->get($contact->id);
            if ($log === null) {
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
