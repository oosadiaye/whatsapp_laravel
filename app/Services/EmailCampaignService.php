<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EmailCampaignDispatch;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailSuppression;
use Illuminate\Support\Collection;

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

        $suppressed = EmailSuppression::query()->pluck('email'); // already lowercased

        return Contact::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('contact_groups.id', $groupIds))
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->reject(fn (Contact $c) => $suppressed->contains(EmailSuppression::normalize((string) $c->email)))
            ->unique(fn (Contact $c) => EmailSuppression::normalize((string) $c->email))
            ->values();
    }

    /**
     * Queue a campaign for immediate sending.
     */
    public function launch(EmailCampaign $campaign): void
    {
        $campaign->update([
            'status' => EmailCampaign::STATUS_QUEUED,
            'started_at' => $campaign->started_at ?? now(),
        ]);

        EmailCampaignDispatch::dispatch($campaign->id);
    }
}
