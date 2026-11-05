<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a qualified lead into a contactable one by asking the platform for the
 * email, which spends credits. This is the ONLY place that happens, so the
 * guards live here:
 *
 *  - idempotent: a lead whose email is already revealed (or known to be
 *    unavailable) is never revealed again;
 *  - budgeted: a profile cannot exceed its monthly credit budget;
 *  - a credit is counted only when an email came back (the platform does not
 *    charge for an empty result);
 *  - a revealed address that duplicates another lead, an existing Contact (even
 *    a deleted one) or a suppressed address is rejected and never stored.
 *
 * A platform failure propagates as LeadSourceException so the runner can stop.
 */
final class LeadRevealer
{
    public const REVEALED = 'revealed';

    public const NO_EMAIL = 'no_email';

    public const DUPLICATE = 'duplicate';

    public const SKIPPED = 'skipped';

    public const BUDGET = 'budget';

    private const CREDITS_PER_REVEAL = 1;

    public function __construct(private readonly LeadSourceRegistry $sources) {}

    public function creditsUsedThisMonth(LeadProfile $profile): int
    {
        $monthStart = now(config('app.business_timezone', config('app.timezone')))->startOfMonth()->utc();

        return self::CREDITS_PER_REVEAL * LeadEvent::query()
            ->where('type', 'revealed')
            ->where('created_at', '>=', $monthStart)
            ->whereHas('lead', fn ($lead) => $lead->where('lead_profile_id', $profile->id))
            ->count();
    }

    /**
     * @throws LeadSourceException
     */
    public function reveal(Lead $lead, LeadProfile $profile): string
    {
        if ($lead->email_status !== Lead::EMAIL_UNREVEALED || $lead->email !== null) {
            return self::SKIPPED;
        }

        if ($this->creditsUsedThisMonth($profile) + self::CREDITS_PER_REVEAL > $profile->monthly_reveal_budget) {
            return self::BUDGET;
        }

        $revealed = $this->sources->get($lead->source)->reveal($this->toData($lead));

        if ($revealed->email === null) {
            $lead->update([
                'email_status' => Lead::EMAIL_UNAVAILABLE,
                'status' => Lead::STATUS_REJECTED,
                'qualification_reason' => 'The platform returned no email address.',
            ]);
            LeadEvent::record($lead, 'reveal_empty');

            return self::NO_EMAIL;
        }

        // The credit is spent the moment an email comes back, even if we then reject it.
        LeadEvent::record($lead, 'revealed', ['credits' => self::CREDITS_PER_REVEAL]);

        $email = mb_strtolower(trim($revealed->email));
        $duplicateReason = $this->duplicateReason($lead, $email);
        if ($duplicateReason !== null) {
            return $this->reject($lead, $duplicateReason);
        }

        try {
            $lead->update([
                'email' => $email,
                'email_status' => $revealed->emailStatus,
                'name' => $revealed->name,
                'title' => $revealed->title,
                'company' => $revealed->company,
                'domain' => $revealed->domain,
                'country' => $revealed->country,
                'linkedin_url' => $revealed->linkedinUrl,
                'revealed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process stored the same address between our check and the write.
            // Drop the unsaved revealed values so the rejection write cannot re-send the email.
            $lead->refresh();

            return $this->reject($lead, 'Duplicate of another lead.');
        }

        return self::REVEALED;
    }

    private function duplicateReason(Lead $lead, string $email): ?string
    {
        $other = Lead::query()->where('email', $email)->whereKeyNot($lead->id)->first();
        if ($other !== null) {
            return "Duplicate of lead #{$other->id}.";
        }

        if (Contact::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'Already a contact.';
        }

        if (EmailSuppression::isSuppressed($email)) {
            return 'The address is on the suppression list.';
        }

        return null;
    }

    private function reject(Lead $lead, string $reason): string
    {
        $lead->update([
            'status' => Lead::STATUS_REJECTED,
            'email_status' => Lead::EMAIL_UNAVAILABLE,
            'qualification_reason' => $reason,
        ]);
        LeadEvent::record($lead, 'rejected', ['reason' => $reason]);

        return self::DUPLICATE;
    }

    private function toData(Lead $lead): LeadData
    {
        return new LeadData(
            source: $lead->source,
            sourceId: $lead->source_id,
            name: $lead->name,
            title: $lead->title,
            company: $lead->company,
            email: $lead->email,
            emailStatus: $lead->email_status,
            domain: $lead->domain,
            country: $lead->country,
            phone: $lead->phone,
            linkedinUrl: $lead->linkedin_url,
            raw: (array) ($lead->raw ?? []),
        );
    }
}
