<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\CampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSuppression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends one campaign email to one recipient and records the outcome on its
 * EmailLog. Re-checks suppression at send time (the recipient may have
 * unsubscribed after the fan-out queued this job) and marks the campaign
 * complete once no recipient is still queued.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $logId)
    {
    }

    public function handle(): void
    {
        $log = EmailLog::with(['campaign', 'contact'])->find($this->logId);
        if ($log === null || $log->campaign === null) {
            return;
        }

        $campaign = $log->campaign;
        if ($campaign->status === EmailCampaign::STATUS_CANCELLED) {
            return;
        }

        // Someone may have unsubscribed between fan-out and now — honour it.
        if (EmailSuppression::isSuppressed($log->email)) {
            $log->update(['status' => EmailLog::STATUS_UNSUBSCRIBED]);
            $this->completeIfDone($campaign);

            return;
        }

        try {
            Mail::to($log->email)->send(
                new CampaignEmail($campaign, $log->email, $log->contact?->name),
            );

            $log->update(['status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'error' => null]);
            $campaign->increment('sent_count');
        } catch (\Throwable $e) {
            // At-most-once: record the failure and move on rather than retrying
            // into a possible duplicate send.
            $log->update(['status' => EmailLog::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
            $campaign->increment('failed_count');
        }

        $this->completeIfDone($campaign);
    }

    private function completeIfDone(EmailCampaign $campaign): void
    {
        if ($campaign->logs()->where('status', EmailLog::STATUS_QUEUED)->exists()) {
            return;
        }

        $campaign->update([
            'status' => EmailCampaign::STATUS_SENT,
            'completed_at' => now(),
            'last_run_at' => now(),
        ]);
    }
}
