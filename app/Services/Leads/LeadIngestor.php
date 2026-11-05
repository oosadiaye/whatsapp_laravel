<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores pulled leads. De-duplication is on (source, source id): the same person
 * is never stored twice, even when two profiles find them. Email-based dedupe
 * (against Contacts and the suppression list) happens when the email is revealed,
 * in a later phase, because search results carry no email.
 */
final class LeadIngestor
{
    /**
     * @param  list<LeadData>  $leads
     * @return array{created: int, skipped: int}
     */
    public function ingest(LeadProfile $profile, array $leads): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($leads as $data) {
            if (Lead::query()->where('source', $data->source)->where('source_id', $data->sourceId)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $lead = Lead::create([
                    'lead_profile_id' => $profile->id,
                    'source' => $data->source,
                    'source_id' => $data->sourceId,
                    'email' => $data->email,
                    'email_status' => $data->emailStatus,
                    'name' => $data->name,
                    'title' => $data->title,
                    'company' => $data->company,
                    'domain' => $data->domain,
                    'country' => $data->country,
                    'phone' => $data->phone,
                    'linkedin_url' => $data->linkedinUrl,
                    'raw' => $data->raw === [] ? null : $data->raw,
                    'status' => Lead::STATUS_NEW,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent pull stored it between the check and the insert.
                $skipped++;

                continue;
            }

            LeadEvent::record($lead, 'pulled', ['profile_id' => $profile->id]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
