<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EmailCampaignDispatch;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailSuppression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates email-campaign sending: resolves the recipient set and kicks off
 * the fan-out. Kept thin + testable; the actual queueing lives in the jobs.
 */
class EmailCampaignService
{
    /**
     * The addressable audience for a campaign: active contacts in its target
     * groups that have an email, are not suppressed, deduped by email.
     *
     * @return Collection<int, Contact>
     */
    public function recipients(EmailCampaign $campaign): Collection
    {
        $groupIds = $campaign->contactGroups()->pluck('contact_groups.id');
        if ($groupIds->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('contact_groups.id', $groupIds))
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            // Exclude suppressed addresses in SQL (audit M5) instead of plucking
            // the whole suppression list and doing an O(n*m) PHP contains().
            // Suppression emails are stored already-lowercased, so match on
            // LOWER(contacts.email).
            ->whereNotIn(
                DB::raw('LOWER(email)'),
                EmailSuppression::query()->select('email'),
            )
            ->get()
            // Case-insensitive dedupe still runs in PHP: two rows 'A@x'/'a@x' are
            // distinct contacts but one recipient.
            ->unique(fn (Contact $c) => EmailSuppression::normalize((string) $c->email))
            ->values();
    }

    /**
     * Queue a campaign for immediate sending.
     */
    public function launch(EmailCampaign $campaign): void
    {
        // Audit M11: a non-delivering transport (log/array) makes a campaign
        // report SENT while nothing arrives. The controller surfaces this in the
        // UI on manual launch; log it too so the scheduled/cron path (which has
        // no UI) isn't a silent black hole.
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array', ''], true)) {
            Log::warning('Email campaign launched with a non-delivering mail transport', [
                'campaign_id' => $campaign->id,
                'mail_mailer' => $mailer,
            ]);
        }

        $campaign->update([
            'status' => EmailCampaign::STATUS_QUEUED,
            'started_at' => $campaign->started_at ?? now(),
        ]);

        EmailCampaignDispatch::dispatch($campaign->id);
    }
}
