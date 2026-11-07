<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;

/**
 * Turns a qualified lead into a Contact the first time we are about to write to them. Uses the
 * soft-delete-safe resolver, because a Contact with the same address may have been deleted and the
 * unique index still counts it. The lead's owner is the person whose mailbox sends the outreach, so
 * the answers land in that person's inbox and the alerts go to them.
 */
final class LeadPromoter
{
    public function promote(Lead $lead, LeadProfile $profile): Contact
    {
        $ownerId = (int) ($profile->senderAccount?->user_id ?? $profile->created_by);

        $contact = Contact::firstOrCreateIncludingTrashed(
            ['user_id' => $ownerId, 'email' => (string) $lead->email],
            [
                'name' => $lead->name,
                'phone' => null,
                'is_active' => true,
                'custom_fields' => array_filter([
                    'company' => $lead->company,
                    'title' => $lead->title,
                    'source' => 'lead_crm',
                ]),
            ],
        );

        $changes = ['owner_user_id' => $ownerId];
        if ($lead->contact_id === null) {
            $changes['contact_id'] = $contact->id;
        }
        $lead->forceFill($changes)->save();

        if (! $lead->events()->where('type', 'promoted')->exists()) {
            LeadEvent::record($lead, 'promoted', ['contact_id' => $contact->id]);
        }

        return $contact;
    }
}
