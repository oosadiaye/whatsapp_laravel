<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Support\AiConfig;
use App\Support\LeadConfig;
use Illuminate\Support\Facades\Cache;

/**
 * One qualification pass for one profile. Safe to run often: it is capped per
 * run (BATCH), per business day (the profile's daily AI cap), serialised per
 * profile by a lock, and does nothing while the kill switch is on.
 *
 * Order: (A) reveal qualified leads still waiting for credit or for a transient
 * error to clear; (B) qualify new leads and reveal each pass immediately while
 * revealing is still possible. A platform or budget problem stops REVEALING for
 * the rest of the run but never stops qualifying.
 */
final class LeadQualificationRunner
{
    private const BATCH = 20;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly LeadQualifier $qualifier,
        private readonly LeadRevealer $revealer,
        private readonly LeadSourceRegistry $sources,
    ) {}

    /**
     * @return array{qualified: int, rejected: int, revealed: int, deferred: int, failed: int, errors: list<string>}
     */
    public function run(LeadProfile $profile): array
    {
        $out = ['qualified' => 0, 'rejected' => 0, 'revealed' => 0, 'deferred' => 0, 'failed' => 0, 'errors' => []];

        if (LeadConfig::killSwitchOn() || ! $profile->is_active) {
            return $out;
        }

        if (! AiConfig::isConfigured()) {
            $out['errors'][] = 'No AI provider is configured, so leads cannot be qualified. Add a key in Settings.';
            $profile->update(['last_qualify_error' => $out['errors'][0]]);

            return $out;
        }

        $lock = Cache::lock("lead-qualify:{$profile->id}", 600);
        if (! $lock->get()) {
            $out['errors'][] = 'A qualification run for this profile is already in progress.';

            return $out;
        }

        try {
            $canReveal = true;
            $this->revealWaiting($profile, $out, $canReveal);
            $this->qualifyNew($profile, $out, $canReveal);
        } finally {
            $lock->release();
        }

        $profile->update([
            'last_qualify_error' => $out['errors'] === [] ? null : implode(' ', array_values(array_unique($out['errors']))),
        ]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function revealWaiting(LeadProfile $profile, array &$out, bool &$canReveal): void
    {
        $waiting = Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('status', Lead::STATUS_QUALIFIED)
            ->where('email_status', Lead::EMAIL_UNREVEALED)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($waiting as $lead) {
            if (! $canReveal) {
                break;
            }
            $this->tryReveal($lead, $profile, $out, $canReveal);
        }
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function qualifyNew(LeadProfile $profile, array &$out, bool &$canReveal): void
    {
        $aiUsed = $this->aiDecisionsToday($profile);

        $leads = Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('status', Lead::STATUS_NEW)
            ->where('qualify_attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($leads as $lead) {
            // Free, code-decided rejections are always allowed; the cap only stops AI calls.
            if ($aiUsed >= $profile->daily_qualify_cap && $this->qualifier->freeRejection($lead, $profile) === null) {
                continue;
            }

            $result = $this->qualifier->qualify($lead, $profile);

            if ($result === null) {
                $aiUsed++;
                $this->recordFailure($lead, $out);

                continue;
            }

            if ($result->byAi) {
                $aiUsed++;
            }

            $this->apply($lead, $profile, $result, $out, $canReveal);
        }
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function apply(Lead $lead, LeadProfile $profile, QualificationResult $result, array &$out, bool &$canReveal): void
    {
        $lead->update([
            'score' => $result->score,
            'tier' => $result->tier,
            'qualification_reason' => $result->reason,
        ]);

        if (! $result->passed()) {
            $lead->update(['status' => Lead::STATUS_REJECTED]);
            LeadEvent::record($lead, 'rejected', ['reason' => $result->reason, 'score' => $result->score], $result->byAi ? 'ai' : 'system');
            $out['rejected']++;

            return;
        }

        $lead->update(['status' => Lead::STATUS_QUALIFIED, 'qualified_at' => now()]);
        LeadEvent::record($lead, 'qualified', ['score' => $result->score, 'tier' => $result->tier], 'ai');
        $out['qualified']++;

        if ($canReveal) {
            $this->tryReveal($lead, $profile, $out, $canReveal);
        } else {
            $out['deferred']++;
        }
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function tryReveal(Lead $lead, LeadProfile $profile, array &$out, bool &$canReveal): void
    {
        if (! $this->sources->has($lead->source)) {
            $out['errors'][] = "Unknown lead source \"{$lead->source}\".";
            $out['deferred']++;

            return;
        }

        $source = $this->sources->get($lead->source);
        if (! $source->isConfigured()) {
            $out['errors'][] = "{$source->label()} is not configured, so emails cannot be revealed. Add its key under Lead Sources.";
            $out['deferred']++;
            $canReveal = false;

            return;
        }

        try {
            $outcome = $this->revealer->reveal($lead, $profile);
        } catch (LeadSourceException $e) {
            $out['errors'][] = $e->getMessage();
            $out['deferred']++;
            $canReveal = false;

            return;
        }

        if ($outcome === LeadRevealer::REVEALED) {
            $out['revealed']++;
        } elseif ($outcome === LeadRevealer::DUPLICATE || $outcome === LeadRevealer::NO_EMAIL) {
            $out['rejected']++;
        } elseif ($outcome === LeadRevealer::BUDGET) {
            $out['deferred']++;
            $out['errors'][] = 'Monthly reveal budget reached; qualified leads wait until next month or a larger budget.';
            $canReveal = false;
        }
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function recordFailure(Lead $lead, array &$out): void
    {
        $lead->increment('qualify_attempts');
        $attempts = (int) $lead->qualify_attempts;

        LeadEvent::record($lead, 'qualify_failed', ['attempts' => $attempts, 'gave_up' => $attempts >= self::MAX_ATTEMPTS], 'ai');
        $out['failed']++;
        $out['errors'][] = 'The AI did not return a usable answer for some leads; they will be retried.';
    }

    private function aiDecisionsToday(LeadProfile $profile): int
    {
        $dayStart = now(config('app.business_timezone', config('app.timezone')))->startOfDay()->utc();

        return LeadEvent::query()
            ->where('actor', 'ai')
            ->whereIn('type', ['qualified', 'rejected', 'qualify_failed'])
            ->where('created_at', '>=', $dayStart)
            ->whereHas('lead', fn ($lead) => $lead->where('lead_profile_id', $profile->id))
            ->count();
    }
}
