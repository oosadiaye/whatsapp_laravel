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

        // Resolve the audience in ONE query (a contact in several of the
        // campaign's groups appears once) instead of an N+1 get()-per-group
        // that materialized every group's contacts separately.
        $groupIds = $this->campaign->contactGroups->pluck('id');
        $contacts = Contact::query()
            ->active()
            ->whereIn('id', function ($q) use ($groupIds) {
                $q->select('contact_id')
                    ->from('contact_group')
                    ->whereIn('group_id', $groupIds);
            })
            ->get();

        $this->campaign->update([
            'total_contacts' => $contacts->count(),
        ]);

        if ($contacts->isEmpty()) {
            $this->campaign->update([
                'status' => 'COMPLETED',
                'completed_at' => Carbon::now(),
            ]);

            return;
        }

        $intervalMs = (60 / $this->campaign->rate_per_minute) * 1000;
        $delay = 0;

        foreach ($contacts as $contact) {
            $log = MessageLog::create([
                'campaign_id' => $this->campaign->id,
                'contact_id' => $contact->id,
                'phone' => $contact->phone,
                'status' => 'PENDING',
                'queued_at' => Carbon::now(),
            ]);

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
