<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use App\Services\EmailCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a campaign out into one queued {@see SendCampaignEmail} per recipient,
 * spacing them by the campaign's rate_per_minute so bulk sends don't trip the
 * provider's rate limits. Mirrors the WhatsApp CampaignBatchDispatch pattern.
 */
class EmailCampaignDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One attempt. The atomic QUEUED->SENDING claim in handle() already makes a
    // re-run a no-op, but $tries=1 keeps this consistent with the other fan-out
    // jobs and avoids a retry churning through an already-claimed campaign.
    public int $tries = 1;

    public function __construct(public readonly int $campaignId)
    {
    }

    public function handle(EmailCampaignService $service): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if ($campaign === null || $campaign->status === EmailCampaign::STATUS_CANCELLED) {
            return;
        }

        // Idempotency guard (audit H2): atomically claim the QUEUED campaign for
        // sending. Every dispatch path goes through EmailCampaignService::launch,
        // which sets QUEUED first — so a job that is released and re-run (worker
        // timeout under a short retry_after, or an accidental double dispatch)
        // finds the campaign already SENDING/SENT and updates 0 rows here,
        // bailing before it fans out a SECOND batch of duplicate emails.
        $claimed = EmailCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', EmailCampaign::STATUS_QUEUED)
            ->update(['status' => EmailCampaign::STATUS_SENDING]);

        if ($claimed === 0) {
            return;
        }

        $campaign->refresh();

        $recipients = $service->recipients($campaign);

        $campaign->update([
            'total_recipients' => $recipients->count(),
        ]);

        if ($recipients->isEmpty()) {
            $campaign->update([
                'status' => EmailCampaign::STATUS_SENT,
                'completed_at' => now(),
                'last_run_at' => now(),
            ]);

            return;
        }

        // Even spacing: rate_per_minute sends per 60s.
        $perSecond = max(1, (int) $campaign->rate_per_minute) / 60;

        foreach ($recipients->values() as $i => $contact) {
            $log = EmailLog::create([
                'email_campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => EmailSuppression::normalize((string) $contact->email),
                'status' => EmailLog::STATUS_QUEUED,
            ]);

            $delaySeconds = (int) floor($i / $perSecond);

            SendCampaignEmail::dispatch($log->id)->delay(now()->addSeconds($delaySeconds));
        }
    }
}
