<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\MessageLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

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

        $this->campaign->update([
            'status' => 'RUNNING',
            'started_at' => Carbon::now(),
        ]);

        $contacts = $this->campaign
            ->contactGroups
            ->flatMap(fn ($g) => $g->contacts()->active()->get())
            ->unique('id');

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
}
