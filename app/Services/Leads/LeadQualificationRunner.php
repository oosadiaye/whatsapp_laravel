<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Support\AiConfig;
use App\Support\LeadConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One qualification pass for one profile. Safe to run often: it is capped per
 * run (BATCH and RUN_SECONDS), per business day (the profile's daily AI cap),
 * serialised per profile by a lock, and does nothing while the kill switch is on.
 *
 * Order: (A) reveal qualified leads still waiting for credit or for a transient
 * error to clear (hot first); (B) qualify new leads and reveal each pass
 * immediately while revealing is still possible. A platform or budget problem
 * stops REVEALING for the rest of the run but never stops qualifying, and one
 * lead that blows up never stops the others.
 */
final class LeadQualificationRunner
{
    private const BATCH = 20;

    /** How many new leads are looked at per run, so leads waiting only for the cap do not hide free rejections. */
    private const SCAN = 200;

    private const MAX_ATTEMPTS = 3;

    private const MAX_CONSECUTIVE_AI_FAILURES = 3;

    private const RUN_SECONDS = 480;

    private const UNEXPECTED_ERROR = 'An unexpected error occurred while processing a lead; see the log.';

    private ?Carbon $deadline = null;

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
            $this->deadline = now()->addSeconds(self::RUN_SECONDS);
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
    private function timeIsUp(array &$out): bool
    {
        if ($this->deadline === null || now()->lessThanOrEqualTo($this->deadline)) {
            return false;
        }

        $out['errors'][] = 'Run time limit reached; the rest continues next run.';

        return true;
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function revealWaiting(LeadProfile $profile, array &$out, bool &$canReveal): void
    {
        // Hot before warm so scarce credits go to the best leads; least recently touched first so a
        // lead that keeps failing moves to the back instead of starving the rest.
        $waiting = Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('status', Lead::STATUS_QUALIFIED)
            ->where('email_status', Lead::EMAIL_UNREVEALED)
            ->orderByRaw("CASE tier WHEN 'hot' THEN 0 ELSE 1 END")
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($waiting as $lead) {
            if (! $canReveal || $this->timeIsUp($out)) {
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
            ->limit(self::SCAN)
            ->get();

        $processed = 0;
        $answered = 0;
        $consecutiveFailures = 0;
        /** @var list<Lead> $unanswered */
        $unanswered = [];

        foreach ($leads as $lead) {
            if ($processed >= self::BATCH || $this->timeIsUp($out)) {
                break;
            }

            try {
                // Free, code-decided rejections are always allowed; the cap only stops AI calls.
                if ($aiUsed >= $profile->daily_qualify_cap && $this->qualifier->freeRejection($lead, $profile) === null) {
                    continue;
                }

                $processed++;
                $result = $this->qualifier->qualify($lead, $profile);

                if ($result === null) {
                    $aiUsed++;
                    $consecutiveFailures++;
                    $unanswered[] = $lead;
                    $out['failed']++;

                    if ($consecutiveFailures >= self::MAX_CONSECUTIVE_AI_FAILURES) {
                        $out['errors'][] = 'The AI provider is not answering; will retry on the next run.';
                        break;
                    }

                    continue;
                }

                if ($result->byAi) {
                    $aiUsed++;
                    $answered++;
                    $consecutiveFailures = 0;
                }

                $this->apply($lead, $result, $out);
            } catch (\Throwable $e) {
                report($e);
                $this->recordFailure($lead);
                $out['failed']++;
                $out['errors'][] = self::UNEXPECTED_ERROR;

                continue;
            }

            if ($lead->status === Lead::STATUS_QUALIFIED) {
                $this->revealOrDefer($lead, $profile, $out, $canReveal);
            }
        }

        $this->settleUnanswered($unanswered, $answered, $out);
    }

    /**
     * An AI answer that never came is only held against the lead when the provider was answering
     * other leads in the same run; otherwise the provider is assumed down and nobody is penalised.
     *
     * @param  list<Lead>  $unanswered
     * @param  array<string, mixed>  $out
     */
    private function settleUnanswered(array $unanswered, int $answered, array &$out): void
    {
        if ($unanswered === []) {
            return;
        }

        if ($answered === 0) {
            $out['errors'][] = 'The AI provider is not answering; will retry on the next run.';

            return;
        }

        foreach ($unanswered as $lead) {
            $this->recordFailure($lead);
        }
        $out['errors'][] = 'The AI did not return a usable answer for some leads; they will be retried.';
    }

    /**
     * Saves the verdict and its event together, so a lead is never qualified without its event.
     *
     * @param  array<string, mixed>  $out
     */
    private function apply(Lead $lead, QualificationResult $result, array &$out): void
    {
        $passed = $result->passed();

        DB::transaction(function () use ($lead, $result, $passed): void {
            $lead->update([
                'score' => $result->score,
                'tier' => $result->tier,
                'qualification_reason' => $result->reason,
                'status' => $passed ? Lead::STATUS_QUALIFIED : Lead::STATUS_REJECTED,
                'qualified_at' => $passed ? now() : $lead->qualified_at,
            ]);

            if ($passed) {
                LeadEvent::record($lead, 'qualified', ['score' => $result->score, 'tier' => $result->tier], 'ai');
            } else {
                LeadEvent::record($lead, 'rejected', ['reason' => $result->reason, 'score' => $result->score], $result->byAi ? 'ai' : 'system');
            }
        });

        $out[$passed ? 'qualified' : 'rejected']++;
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function revealOrDefer(Lead $lead, LeadProfile $profile, array &$out, bool &$canReveal): void
    {
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
            $this->rejectUnknownSource($lead, $out);

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
        } catch (\Throwable $e) {
            report($e);
            // Query-level touch: the instance may still hold unsaved values from the failed write.
            Lead::whereKey($lead->id)->update(['updated_at' => now()]);
            $out['errors'][] = self::UNEXPECTED_ERROR;
            $out['deferred']++;

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
    private function rejectUnknownSource(Lead $lead, array &$out): void
    {
        $reason = 'Unknown lead source';

        DB::transaction(function () use ($lead, $reason): void {
            $lead->update(['status' => Lead::STATUS_REJECTED, 'qualification_reason' => $reason]);
            LeadEvent::record($lead, 'rejected', ['reason' => $reason, 'source' => $lead->source]);
        });

        $out['rejected']++;
    }

    /**
     * Counts one failed attempt with plain queries, because the instance may hold unsaved values
     * from the write that just failed and must not be saved again.
     */
    private function recordFailure(Lead $lead): void
    {
        Lead::whereKey($lead->id)->increment('qualify_attempts');
        $attempts = (int) Lead::whereKey($lead->id)->value('qualify_attempts');

        LeadEvent::record($lead, 'qualify_failed', ['attempts' => $attempts, 'gave_up' => $attempts >= self::MAX_ATTEMPTS], 'ai');
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
