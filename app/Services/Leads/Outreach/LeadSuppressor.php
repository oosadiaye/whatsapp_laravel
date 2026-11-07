<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadMessage;

/**
 * The one way a lead leaves outreach for good: the address goes on the global suppression list (so no
 * campaign or sequence can reach it either), the lead becomes unsubscribed, waiting drafts are discarded
 * and follow-ups stop. Safe to call twice.
 */
final class LeadSuppressor
{
    public function suppress(Lead $lead, string $reason, string $actor = 'system'): void
    {
        if (filled($lead->email)) {
            EmailSuppression::suppress((string) $lead->email, EmailSuppression::REASON_UNSUBSCRIBE);
        }

        $already = $lead->status === Lead::STATUS_UNSUBSCRIBED;

        $lead->forceFill([
            'status' => Lead::STATUS_UNSUBSCRIBED,
            'next_followup_at' => null,
            'last_activity_at' => now(),
        ])->save();

        LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->whereIn('status', [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_SENDING])
            ->update(['status' => LeadMessage::STATUS_DISCARDED, 'failure' => 'Lead unsubscribed.']);

        if (! $already) {
            LeadEvent::record($lead, 'suppressed', ['reason' => $reason], $actor);
        }
    }
}
