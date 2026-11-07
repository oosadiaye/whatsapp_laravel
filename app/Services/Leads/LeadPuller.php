<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Support\Facades\Cache;

/**
 * One pull pass for a profile: for each of its sources, fetch ONE page (if the
 * source is configured, not exhausted and today's cap is not reached), ingest it
 * and advance that source's cursor. The scheduler calls this hourly, so a profile
 * fills its daily cap gradually and a rate limit only delays the next page.
 *
 * Errors are recorded on the profile and never advance the cursor, so nothing is
 * skipped; the next run simply retries the same page.
 */
final class LeadPuller
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly LeadSourceRegistry $sources,
        private readonly LeadIngestor $ingestor,
    ) {}

    /**
     * @return array{created: int, skipped: int, failed: int, errors: list<string>}
     */
    public function pull(LeadProfile $profile): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        if (LeadConfig::killSwitchOn() || ! $profile->is_active || ! $profile->hasQuery()) {
            return $result;
        }

        $lock = Cache::lock("lead-pull:{$profile->id}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            $result['errors'][] = 'A pull for this profile is already running.';

            return $result;
        }

        try {
            return $this->run($profile, $result);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{created: int, skipped: int, failed: int, errors: list<string>}  $result
     * @return array{created: int, skipped: int, failed: int, errors: list<string>}
     */
    private function run(LeadProfile $profile, array $result): array
    {
        $query = LeadQuery::fromArray($profile->lead_query);
        $state = $profile->pull_state ?? [];

        foreach ($profile->sources as $key) {
            if (! $this->sources->has($key)) {
                $result['errors'][] = "Unknown lead source \"{$key}\".";

                continue;
            }

            $source = $this->sources->get($key);
            if (! $source->isConfigured()) {
                $result['errors'][] = "{$source->label()} is not configured: add its API key under Lead Sources.";

                continue;
            }

            $cursor = $profile->sourceState($key);
            if ($cursor['exhausted'] || $this->pulledToday($profile, $key) >= $profile->daily_pull_cap) {
                continue;
            }

            try {
                $page = $source->search($query, $cursor['next_page'], LeadConfig::pageSize());
            } catch (LeadSourceException $e) {
                $result['errors'][] = $e->getMessage();

                continue;
            }

            $ingested = $this->ingestor->ingest($profile, $page->leads);
            $result['created'] += $ingested['created'];
            $result['skipped'] += $ingested['skipped'];
            $result['failed'] += $ingested['failed'];

            if ($ingested['failed'] > 0) {
                $result['errors'][] = "{$ingested['failed']} lead(s) from {$source->label()} could not be stored (see the application log).";
            }

            // The cursor advances even when some rows failed: a bad row must not stall the profile.
            $state[$key] = [
                'next_page' => $cursor['next_page'] + 1,
                'exhausted' => ! $page->hasMore || $page->leads === [],
            ];
        }

        $profile->forceFill([
            'pull_state' => $state === [] ? null : $state,
            'last_pulled_at' => now(),
            'last_pull_error' => $result['errors'] === [] ? null : implode(' ', $result['errors']),
        ])->save();

        return $result;
    }

    private function pulledToday(LeadProfile $profile, string $source): int
    {
        $startOfToday = now(config('app.business_timezone', config('app.timezone')))->startOfDay()->utc();

        return Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('source', $source)
            ->where('created_at', '>=', $startOfToday)
            ->count();
    }
}
