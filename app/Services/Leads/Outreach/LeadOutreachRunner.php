<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Support\AiConfig;
use App\Support\LeadConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Writes the outreach. For one profile per run: draft the first email for newly qualified leads
 * (hot first), and a follow-up for contacted leads whose wait is over and who have not answered.
 * Each draft either goes straight to `approved` (the autonomy level allows it) or waits for a person.
 * Sending itself is not done here: see {@see LeadMessageSender}.
 *
 * Bounded on purpose: a few drafts per run, no more than a queue's worth waiting at once, a stop after
 * consecutive AI failures (an outage must not burn through the leads), and one lead's trouble never
 * stops the others.
 */
final class LeadOutreachRunner
{
    private const DRAFTS_PER_RUN = 10;

    private const MAX_WAITING = 50;

    private const OUTAGE_AFTER = 3;

    public function __construct(
        private readonly LeadMessageDrafter $drafter,
        private readonly LeadPromoter $promoter,
        private readonly AutonomyPolicy $autonomy,
        private readonly LeadAlerts $alerts,
    ) {}

    /**
     * @return array{drafted: int, awaiting: int, failed: int, note: ?string}
     */
    public function run(LeadProfile $profile): array
    {
        $summary = ['drafted' => 0, 'awaiting' => 0, 'failed' => 0, 'note' => null];

        if (LeadConfig::killSwitchOn()) {
            $summary['note'] = 'Paused by the global kill switch.';

            return $summary;
        }

        $lock = Cache::lock("lead-outreach:{$profile->id}", 300);
        if (! $lock->get()) {
            $summary['note'] = 'An outreach run for this profile is already in progress.';

            return $summary;
        }

        try {
            return $this->runLocked($profile, $summary);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{drafted: int, awaiting: int, failed: int, note: ?string}  $summary
     * @return array{drafted: int, awaiting: int, failed: int, note: ?string}
     */
    private function runLocked(LeadProfile $profile, array $summary): array
    {
        $profile->loadMissing('senderAccount');
        $problem = $this->setupProblem($profile);
        if ($problem !== null) {
            $profile->forceFill(['last_outreach_error' => $problem])->save();
            $summary['note'] = $problem;

            return $summary;
        }
        $profile->forceFill(['last_outreach_error' => null])->save();

        $budget = min(self::DRAFTS_PER_RUN, max(0, self::MAX_WAITING - $this->waiting($profile)));
        $senderName = $this->senderName($profile);
        $consecutiveFailures = 0;

        foreach ($this->followUpsDue($profile) as $lead) {
            if ($budget <= 0 || $consecutiveFailures >= self::OUTAGE_AFTER) {
                break;
            }
            $this->draftOne($lead, $profile, $senderName, LeadMessage::KIND_FOLLOWUP, $summary, $consecutiveFailures);
            $budget--;
        }

        foreach ($this->newLeads($profile, $budget) as $lead) {
            if ($consecutiveFailures >= self::OUTAGE_AFTER) {
                $summary['note'] = 'The AI is not answering; stopped for this run and will retry.';
                break;
            }
            $this->draftOne($lead, $profile, $senderName, LeadMessage::KIND_FIRST, $summary, $consecutiveFailures);
        }

        if ($summary['awaiting'] > 0) {
            $this->alerts->draftsWaiting($profile, $summary['awaiting']);
        }

        return $summary;
    }

    private function setupProblem(LeadProfile $profile): ?string
    {
        $account = $profile->senderAccount;
        if ($account === null || ! $account->is_active || $account->needs_reauth) {
            return 'Choose a connected, active sender mailbox for this profile before outreach can start.';
        }

        if (! AiConfig::isConfigured()) {
            return 'No AI provider is configured.';
        }

        return null;
    }

    /**
     * @param  array{drafted: int, awaiting: int, failed: int, note: ?string}  $summary
     */
    private function draftOne(Lead $lead, LeadProfile $profile, string $senderName, string $kind, array &$summary, int &$consecutiveFailures): void
    {
        try {
            $this->promoter->promote($lead, $profile);

            if ($kind === LeadMessage::KIND_FIRST) {
                $draft = $this->drafter->first($lead, $profile, $senderName);
                $step = 0;
            } else {
                $step = $lead->followups_sent + 1;
                $draft = $this->drafter->followUp($lead, $profile, $senderName, $step, $this->firstSubject($lead));
            }

            if ($draft === null) {
                $summary['failed']++;
                $consecutiveFailures++;

                return;
            }

            $consecutiveFailures = 0;
            $auto = $this->autonomy->sendsOutreachAutomatically($profile);

            $message = LeadMessage::create([
                'lead_id' => $lead->id,
                'kind' => $kind,
                'status' => $auto ? LeadMessage::STATUS_APPROVED : LeadMessage::STATUS_PENDING,
                'step' => $step,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'by_ai' => true,
            ]);

            // A follow-up must not be drafted twice while this one is open.
            $lead->forceFill(['next_followup_at' => null])->save();

            LeadEvent::record($lead, 'draft_created', [
                'kind' => $kind,
                'message_id' => $message->id,
                'autonomy' => $this->autonomy->levelFor($profile),
                'awaiting_approval' => ! $auto,
            ], 'ai');

            $summary['drafted']++;
            if (! $auto) {
                $summary['awaiting']++;
            }
        } catch (\Throwable $e) {
            $summary['failed']++;
            Log::error('Lead outreach: drafting failed for a lead', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Qualified, reachable leads nobody has written to yet. Hot before warm, best score first.
     *
     * @return iterable<Lead>
     */
    private function newLeads(LeadProfile $profile, int $limit): iterable
    {
        if ($limit <= 0) {
            return [];
        }

        return Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('status', Lead::STATUS_QUALIFIED)
            ->whereIn('tier', [Lead::TIER_HOT, Lead::TIER_WARM])
            ->whereNotNull('email')
            ->whereDoesntHave('messages', fn ($q) => $q->where('kind', LeadMessage::KIND_FIRST))
            ->orderByRaw("case when tier = 'hot' then 0 else 1 end")
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return iterable<Lead>
     */
    private function followUpsDue(LeadProfile $profile): iterable
    {
        return Lead::query()
            ->where('lead_profile_id', $profile->id)
            ->where('status', Lead::STATUS_CONTACTED)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<=', now())
            ->where('followups_sent', '<', $profile->followup_count)
            ->whereDoesntHave('messages', fn ($q) => $q
                ->where('kind', LeadMessage::KIND_FOLLOWUP)
                ->whereIn('status', [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_APPROVED, LeadMessage::STATUS_SENDING]))
            ->orderBy('next_followup_at')
            ->limit(self::DRAFTS_PER_RUN)
            ->get();
    }

    private function waiting(LeadProfile $profile): int
    {
        return LeadMessage::query()
            ->whereIn('status', [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_APPROVED])
            ->whereHas('lead', fn ($q) => $q->where('lead_profile_id', $profile->id))
            ->count();
    }

    private function firstSubject(Lead $lead): string
    {
        return (string) LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('kind', LeadMessage::KIND_FIRST)
            ->where('status', LeadMessage::STATUS_SENT)
            ->value('subject');
    }

    private function senderName(LeadProfile $profile): string
    {
        $account = $profile->senderAccount;

        return trim((string) ($account?->display_name ?: $account?->user?->name ?: ''));
    }
}
