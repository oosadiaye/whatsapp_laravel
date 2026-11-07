<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Jobs\HandleLeadReplyJob;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadMessage;
use App\Models\LeadReply;
use App\Support\PromptText;

/**
 * Recognises a prospect's answer in an inbound email. The match is the sender's address against leads we
 * have actually written to. On a match, outreach stops at once (no follow-up may ever go to someone who
 * answered), the reply is recorded and the owner is told straight away; the AI then classifies it in the
 * background. Safe to call twice for the same email.
 */
final class ReplyDetector
{
    public function __construct(private readonly LeadAlerts $alerts) {}

    public function detect(EmailMessage $message): ?LeadReply
    {
        if ($message->direction !== EmailMessage::DIRECTION_INBOUND || blank($message->from_email)) {
            return null;
        }

        $lead = Lead::query()
            ->where('email', mb_strtolower(trim((string) $message->from_email)))
            ->whereNotNull('outreach_started_at')
            ->first();
        if ($lead === null) {
            return null;
        }

        $existing = LeadReply::query()->where('email_message_id', $message->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $reply = LeadReply::create([
            'lead_id' => $lead->id,
            'email_message_id' => $message->id,
            'status' => LeadReply::STATUS_NEW,
        ]);

        $lead->forceFill([
            'status' => $lead->status === Lead::STATUS_UNSUBSCRIBED ? Lead::STATUS_UNSUBSCRIBED : Lead::STATUS_REPLIED,
            'next_followup_at' => null,
            'last_activity_at' => now(),
        ])->save();

        // Nothing queued for this person may still go out as outreach (an answer to the reply is fine).
        LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->whereIn('kind', [LeadMessage::KIND_FIRST, LeadMessage::KIND_FOLLOWUP])
            ->whereIn('status', [LeadMessage::STATUS_PENDING, LeadMessage::STATUS_APPROVED])
            ->update(['status' => LeadMessage::STATUS_DISCARDED, 'failure' => 'The lead replied.']);

        LeadEvent::record($lead, 'replied', ['reply_id' => $reply->id, 'email_message_id' => $message->id], 'prospect');

        $this->alerts->replyArrived($lead->loadMissing('profile'), $this->snippet($message));

        HandleLeadReplyJob::dispatch($reply->id);

        return $reply;
    }

    private function snippet(EmailMessage $message): string
    {
        $text = (string) ($message->body_text ?: strip_tags((string) $message->body_html));

        return PromptText::block(PromptText::withoutQuotedHistory($text), 300);
    }
}
