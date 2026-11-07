<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Jobs\SendUserEmail;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Services\MailClient\OutboundEmail;
use App\Support\LeadConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * The only place a lead email leaves the app. A message becomes `approved` (by a person, or by the
 * autonomy rules) and is sent from here, after re-checking everything that may have changed since it
 * was written: the kill switch, the lead's state, the suppression list, the mailbox and the daily cap.
 * A message that cannot go yet stays `approved` and is picked up by the next sweep.
 */
final class LeadMessageSender
{
    public const SENT = 'sent';

    public const WAITING = 'waiting';

    public const DISCARDED = 'discarded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(private readonly LeadSuppressor $suppressor) {}

    /**
     * Send every approved message that is allowed to go now, oldest first.
     *
     * @return int how many were sent
     */
    public function sweep(int $limit = 50): int
    {
        if (LeadConfig::killSwitchOn()) {
            return 0;
        }

        $sent = 0;
        $messages = LeadMessage::query()
            ->where('status', LeadMessage::STATUS_APPROVED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            try {
                if ($this->send($message) === self::SENT) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                // One bad message must not stop the rest of the queue.
                Log::error('Lead outreach: sending a message crashed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    public function send(LeadMessage $message): string
    {
        if (LeadConfig::killSwitchOn()) {
            return self::WAITING;
        }

        $message->refresh();
        if ($message->status !== LeadMessage::STATUS_APPROVED) {
            return self::SKIPPED;
        }

        $lead = $message->lead()->with('profile.senderAccount')->first();
        $profile = $lead?->profile;
        if ($lead === null || $profile === null) {
            return $this->discard($message, 'The lead or its profile no longer exists.');
        }

        $blocked = $this->blockedReason($message, $lead);
        if ($blocked !== null) {
            return $this->discard($message, $blocked);
        }

        $account = $profile->senderAccount;
        if ($account === null || ! $account->is_active || $account->needs_reauth) {
            return $this->fail($message, $profile, 'The sender mailbox is missing, switched off or needs to be reconnected.');
        }

        $isReply = $message->kind === LeadMessage::KIND_REPLY;
        if (! $isReply && $this->sentToday($profile) >= $profile->daily_send_cap) {
            return self::WAITING;
        }

        // Claim it: two overlapping sweeps (or a sweep and an approval click) must never both send.
        $claimed = LeadMessage::query()
            ->whereKey($message->id)
            ->where('status', LeadMessage::STATUS_APPROVED)
            ->update(['status' => LeadMessage::STATUS_SENDING]);
        if ($claimed === 0) {
            return self::SKIPPED;
        }

        SendUserEmail::dispatch($account->id, $this->outbound($message, $lead, $account->id));

        $message->forceFill(['status' => LeadMessage::STATUS_SENT, 'sent_at' => now()])->save();
        $this->afterSend($message, $lead, $profile);

        return self::SENT;
    }

    private function blockedReason(LeadMessage $message, Lead $lead): ?string
    {
        if (blank($lead->email)) {
            return 'The lead has no email address.';
        }

        if ($lead->status === Lead::STATUS_UNSUBSCRIBED) {
            return 'The lead has unsubscribed.';
        }

        if (EmailSuppression::isSuppressed($lead->email)) {
            $this->suppressor->suppress($lead, 'Address is on the suppression list.');

            return 'The address is on the suppression list.';
        }

        $stopsOutreach = [Lead::STATUS_REPLIED, Lead::STATUS_MEETING, Lead::STATUS_WON, Lead::STATUS_LOST, Lead::STATUS_REJECTED];
        if ($message->kind !== LeadMessage::KIND_REPLY && in_array($lead->status, $stopsOutreach, true)) {
            return 'The lead has already answered or is no longer a prospect.';
        }

        return null;
    }

    private function outbound(LeadMessage $message, Lead $lead, int $accountId): OutboundEmail
    {
        $previous = $this->previousMessage($message, $lead, $accountId);
        $inReplyTo = $previous?->message_id !== null ? '<'.trim($previous->message_id, '<>').'>' : null;
        $references = $inReplyTo === null ? null : trim(($previous->references_header ?? '').' '.$inReplyTo);

        return new OutboundEmail(
            to: [(string) $lead->email],
            subject: $message->subject,
            bodyHtml: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">'.nl2br(e($message->body)).'</div>',
            bodyText: $message->body,
            inReplyTo: $inReplyTo,
            references: $references,
            threadId: $previous?->email_thread_id,
            unsubscribeUrl: URL::signedRoute('email.lead-unsubscribe', ['lead' => $lead->id]),
        );
    }

    /** The message this one continues: the one being answered, or the last thing we sent this person. */
    private function previousMessage(LeadMessage $message, Lead $lead, int $accountId): ?EmailMessage
    {
        if ($message->kind === LeadMessage::KIND_FIRST) {
            return null;
        }

        $answered = $message->reply?->message;
        if ($answered !== null && $answered->email_account_id === $accountId) {
            return $answered;
        }

        return EmailMessage::query()
            ->where('email_account_id', $accountId)
            ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
            ->where('to', 'like', '%"'.$lead->email.'"%')
            ->orderByDesc('id')
            ->first();
    }

    private function afterSend(LeadMessage $message, Lead $lead, LeadProfile $profile): void
    {
        $now = now();
        $changes = ['last_activity_at' => $now];

        if ($message->kind === LeadMessage::KIND_FIRST) {
            $changes += ['status' => Lead::STATUS_CONTACTED, 'outreach_started_at' => $now];
        } elseif ($message->kind === LeadMessage::KIND_FOLLOWUP) {
            $changes += ['followups_sent' => $lead->followups_sent + 1];
        }

        if ($message->kind !== LeadMessage::KIND_REPLY) {
            $sentFollowUps = (int) ($changes['followups_sent'] ?? $lead->followups_sent);
            $more = $sentFollowUps < $profile->followup_count;
            $changes['next_followup_at'] = $more ? $now->copy()->addDays(max(1, $profile->followup_delay_days)) : null;
        }

        $lead->forceFill($changes)->save();

        LeadEvent::record($lead, 'email_sent', [
            'kind' => $message->kind,
            'subject' => $message->subject,
            'approved_by' => $message->decided_by,
        ], $message->decided_by !== null ? (string) $message->decided_by : 'ai');
    }

    private function sentToday(LeadProfile $profile): int
    {
        $startOfToday = now(config('app.business_timezone', config('app.timezone')))->startOfDay()->utc();

        return LeadMessage::query()
            ->whereIn('kind', [LeadMessage::KIND_FIRST, LeadMessage::KIND_FOLLOWUP])
            ->where('status', LeadMessage::STATUS_SENT)
            ->where('sent_at', '>=', $startOfToday)
            ->whereHas('lead', fn ($q) => $q->where('lead_profile_id', $profile->id))
            ->count();
    }

    private function discard(LeadMessage $message, string $reason): string
    {
        $message->forceFill(['status' => LeadMessage::STATUS_DISCARDED, 'failure' => mb_substr($reason, 0, 500)])->save();

        return self::DISCARDED;
    }

    private function fail(LeadMessage $message, LeadProfile $profile, string $reason): string
    {
        $message->forceFill(['status' => LeadMessage::STATUS_FAILED, 'failure' => mb_substr($reason, 0, 500)])->save();
        $profile->forceFill(['last_outreach_error' => $reason])->save();

        return self::FAILED;
    }
}
