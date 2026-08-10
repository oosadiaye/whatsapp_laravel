<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailSequence;
use Illuminate\Support\Facades\Log;

class EmailLeadScoringService
{
    public const SCORE_NONE = 0;

    public const SCORE_SENT = 5;

    public const SCORE_OPENED = 15;

    public const SCORE_CLICKED = 25;

    public const SCORE_REPLIED = 40;

    public function scoreContact(Contact $contact): int
    {
        $score = self::SCORE_NONE;

        // Use the eager-loaded relation when scoreAll() preloaded it (avoids an
        // N+1: one query for all recipients instead of one query per contact);
        // fall back to a direct query when scoring a single contact.
        $recipients = $contact->relationLoaded('emailSequenceRecipients')
            ? $contact->emailSequenceRecipients
            : $contact->emailSequenceRecipients()->get();

        foreach ($recipients as $r) {
            if ($r->reply_count > 0) {
                $score += self::SCORE_REPLIED * $r->reply_count;
            } elseif ($r->click_count > 0) {
                $score += self::SCORE_CLICKED * $r->click_count;
            } elseif ($r->open_count > 0) {
                $score += self::SCORE_OPENED * $r->open_count;
            } elseif (in_array($r->status, [EmailSequence::RECIPIENT_SENT, EmailSequence::RECIPIENT_COMPLETED], true)) {
                $score += self::SCORE_SENT;
            }
        }

        $contact->update([
            'email_lead_score' => min(100, $score),
            'email_open_count' => $recipients->sum('open_count'),
            'email_click_count' => $recipients->sum('click_count'),
            'email_reply_count' => $recipients->sum('reply_count'),
            'last_email_engagement_at' => $recipients->max('last_sent_at'),
        ]);

        return $score;
    }

    public function scoreAll(): int
    {
        $contacts = Contact::query()
            ->whereHas('emailSequenceRecipients')
            ->with('emailSequenceRecipients')
            ->get();

        $count = 0;
        foreach ($contacts as $contact) {
            $this->scoreContact($contact);
            $count++;
        }

        if ($count > 0) {
            Log::info("Lead scoring: updated scores for {$count} contact(s).");
        }

        return $count;
    }
}
