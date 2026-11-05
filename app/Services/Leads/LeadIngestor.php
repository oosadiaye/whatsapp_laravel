<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
     * @return array{created: int, skipped: int, failed: int}
     */
    public function ingest(LeadProfile $profile, array $leads): array
    {
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($leads as $data) {
            $sourceKey = Str::limit($data->source, 32, '');
            $sourceId = Str::limit($data->sourceId, 128, '');

            if (Lead::query()->where('source', $sourceKey)->where('source_id', $sourceId)->exists()) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($profile, $data, $sourceKey, $sourceId): void {
                    $lead = Lead::create([
                        'lead_profile_id' => $profile->id,
                        'source' => $sourceKey,
                        'source_id' => $sourceId,
                        'email' => $this->limit($data->email),
                        'email_status' => $data->emailStatus,
                        'name' => $this->limit($data->name) ?? 'Unknown',
                        'title' => $this->limit($data->title),
                        'company' => $this->limit($data->company),
                        'domain' => $this->limit($data->domain),
                        'country' => $this->limit($data->country),
                        'phone' => $this->limit($data->phone),
                        'linkedin_url' => $this->limit($data->linkedinUrl),
                        'raw' => $data->raw === [] ? null : $data->raw,
                        'status' => Lead::STATUS_NEW,
                    ]);

                    LeadEvent::record($lead, 'pulled', ['profile_id' => $profile->id]);
                });
                $created++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent pull stored it between the check and the insert.
                $skipped++;
            } catch (Throwable $e) {
                // One bad row must not stall the profile: log it and carry on.
                report($e);
                $failed++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** Trim and cap to the column length; empty becomes null. */
    private function limit(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::limit(trim($value), 255, '');

        return $value === '' ? null : $value;
    }
}
