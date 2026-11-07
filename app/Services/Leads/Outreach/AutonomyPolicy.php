<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\LeadProfile;
use App\Support\LeadConfig;

/**
 * The autonomy matrix as three yes/no questions. A profile may override the global
 * default; anything unrecognised falls back to the safest level (approve everything).
 *
 *   approve_all                 every email and every reply waits for a person
 *   auto_first_approve_replies  first emails and follow-ups go out on their own, replies wait
 *   full_auto                   everything goes out on its own (sensitive replies are still escalated)
 */
final class AutonomyPolicy
{
    public const APPROVE_ALL = 'approve_all';

    public const AUTO_FIRST = 'auto_first_approve_replies';

    public const FULL_AUTO = 'full_auto';

    public const LEVELS = [self::APPROVE_ALL, self::AUTO_FIRST, self::FULL_AUTO];

    public const LABELS = [
        self::APPROVE_ALL => 'Manual: I approve every email and reply',
        self::AUTO_FIRST => 'Semi-automatic: first emails and follow-ups go out by themselves, I approve replies',
        self::FULL_AUTO => 'Fully automatic: the AI sends outreach and answers replies by itself',
    ];

    public function levelFor(LeadProfile $profile): string
    {
        $level = $profile->autonomy ?: LeadConfig::autonomyDefault();

        return in_array($level, self::LEVELS, true) ? $level : self::APPROVE_ALL;
    }

    /** First emails and follow-ups are sent without a person approving them. */
    public function sendsOutreachAutomatically(LeadProfile $profile): bool
    {
        return in_array($this->levelFor($profile), [self::AUTO_FIRST, self::FULL_AUTO], true);
    }

    /** An AI-drafted answer to a prospect's reply is sent without a person approving it. */
    public function sendsRepliesAutomatically(LeadProfile $profile): bool
    {
        return $this->levelFor($profile) === self::FULL_AUTO;
    }
}
