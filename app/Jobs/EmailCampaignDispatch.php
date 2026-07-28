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
use Illuminate\Support\Facades\Log;

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

    // Set explicitly (mirrors CampaignBatchDispatch): a pathological large-audience
    // fan-out fails cleanly instead of being silently killed mid-loop. Must stay
    // below the queue retry_after so a timed-out job isn't retried.
    public int $timeout = 300;

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

        // Relaunch safety: skip anyone who already has a log for this campaign so
        // an operator relaunch after a partial/failed run (the recovery path for a
        // $tries=1 job) doesn't create a second QUEUED log — and a duplicate send —
        // per already-dispatched recipient. Mirrors CampaignBatchDispatch.
        $alreadyLogged = EmailLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->pluck('contact_id')
            ->flip();

        if ($alreadyLogged->isNotEmpty()) {
            $recipients = $recipients->reject(fn ($c) => $alreadyLogged->has($c->id))->values();
        }

        if ($recipients->isEmpty() && $alreadyLogged->isEmpty()) {
            $campaign->update([
                'total_recipients' => 0,
                'status' => EmailCampaign::STATUS_SENT,
                'completed_at' => now(),
                'last_run_at' => now(),
            ]);

            return;
        }

        // Even spacing: rate_per_minute sends per 60s.
        $perSecond = max(1, (int) $campaign->rate_per_minute) / 60;
        $index = 0;

        // Chunk the fan-out: bulk-insert each batch of logs in a single statement
        // (not one INSERT per recipient) and dispatch spaced sends per contact.
        foreach ($recipients->chunk(500) as $chunk) {
            $now = now();

            EmailLog::insert($chunk->map(fn ($contact) => [
                'email_campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => EmailSuppression::normalize((string) $contact->email),
                'status' => EmailLog::STATUS_QUEUED,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            // Re-read the rows we just created to get their ids (each contact is
            // unique in the deduped audience, so keying by contact_id is safe).
            $logs = EmailLog::query()
                ->where('email_campaign_id', $campaign->id)
                ->whereIn('contact_id', $chunk->pluck('id'))
                ->where('status', EmailLog::STATUS_QUEUED)
                ->get()
                ->keyBy('contact_id');

            foreach ($chunk as $contact) {
                $log = $logs->get($contact->id);
                if ($log === null) {
                    continue;
                }

                $delaySeconds = (int) floor($index / $perSecond);
                SendCampaignEmail::dispatch($log->id)->delay(now()->addSeconds($delaySeconds));
                $index++;
            }
        }

        // total_recipients = the actual number of logs, which can't drift from
        // what was dispatched (correct across a resumed relaunch too).
        $campaign->update([
            'total_recipients' => EmailLog::where('email_campaign_id', $campaign->id)->count(),
        ]);

        // Reconcile completion for a relaunch that dispatched nothing new (every
        // recipient already logged + terminal). Without this the campaign would
        // sit in SENDING forever, since only a QUEUED->terminal send triggers
        // SendCampaignEmail's own completion check. On a fresh launch the QUEUED
        // logs we just created keep this a no-op.
        $campaign->refresh();
        if (! $campaign->logs()->where('status', EmailLog::STATUS_QUEUED)->exists()) {
            $status = ($campaign->sent_count === 0 && $campaign->failed_count > 0)
                ? EmailCampaign::STATUS_FAILED
                : EmailCampaign::STATUS_SENT;

            $campaign->update([
                'status' => $status,
                'completed_at' => now(),
                'last_run_at' => now(),
            ]);
        }
    }

    /**
     * A crash mid-fan-out (e.g. a DB timeout on a large audience) would otherwise
     * leave the campaign stuck in SENDING with only a partial batch dispatched and
     * no operator signal. Mark it FAILED so it is visible and can be relaunched
     * (the relaunch dedupe above makes that safe).
     */
    public function failed(\Throwable $e): void
    {
        EmailCampaign::query()
            ->whereKey($this->campaignId)
            ->where('status', EmailCampaign::STATUS_SENDING)
            ->update(['status' => EmailCampaign::STATUS_FAILED, 'last_run_at' => now()]);

        Log::error('EmailCampaignDispatch failed', [
            'campaign_id' => $this->campaignId,
            'error' => $e->getMessage(),
        ]);
    }
}
