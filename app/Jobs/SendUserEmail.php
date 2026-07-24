<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Send one message from a connected mailbox — reply or fresh compose (plan B5a).
 *
 * `$tries = 1` ON PURPOSE (the inverse of {@see SyncEmailAccount}): a send has no
 * idempotency key on the wire, so a retry would deliver the message a SECOND time.
 * A transient SMTP hiccup surfaces as a failed job the user can resend, never a
 * silent double-send. The sent copy is stored locally so it appears in the thread
 * immediately (re-sync later dedupes it by the Message-ID we generate here).
 */
class SendUserEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $accountId,
        public readonly OutboundEmail $email,
    ) {
        $this->onQueue('mail-send');
    }

    public function handle(MailAccountProviderFactory $factory): void
    {
        $account = EmailAccount::find($this->accountId);

        if ($account === null || ! $account->is_active) {
            return;
        }

        $sender = $factory->senderFor($account);
        if ($sender === null) {
            return;
        }

        // Stamp a stable Message-ID before sending so the sent header and the
        // stored copy match (and a re-synced Sent-folder copy dedupes cleanly).
        $email = $this->email->withMessageId($this->generateMessageId($account));

        $sender->send($account, $email); // throws on a hard failure -> job fails, no retry

        $this->storeSentCopy($account, $email);
    }

    private function generateMessageId(EmailAccount $account): string
    {
        $at = strrchr($account->email, '@');
        $domain = $at !== false ? substr($at, 1) : 'localhost';

        return '<'.Str::uuid()->toString().'@'.$domain.'>';
    }

    private function storeSentCopy(EmailAccount $account, OutboundEmail $email): void
    {
        $thread = $this->resolveThread($account, $email);

        $thread->messages()->create([
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'message_id' => $email->messageId !== null ? trim($email->messageId, '<>') : null,
            'in_reply_to' => $email->inReplyTo,
            'references_header' => $email->references,
            'from_email' => $account->email,
            'to' => $email->to,
            'cc' => $email->cc,
            'bcc' => $email->bcc,
            'subject' => $email->subject,
            'body_html' => $email->bodyHtml,
            'body_text' => $email->bodyText,
            'is_read' => true,
            'has_attachments' => $email->hasAttachments(),
            'sent_at' => now(),
        ]);

        $thread->forceFill([
            'last_message_at' => now(),
            'subject' => $thread->subject ?: $email->subject,
        ])->save();
    }

    /**
     * A reply joins its existing thread (scoped to this account so a forged
     * threadId can't graft onto someone else's conversation); a fresh compose
     * opens a new Sent thread.
     */
    private function resolveThread(EmailAccount $account, OutboundEmail $email): EmailThread
    {
        if ($email->threadId !== null) {
            $existing = EmailThread::query()
                ->where('email_account_id', $account->id)
                ->whereKey($email->threadId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return EmailThread::create([
            'email_account_id' => $account->id,
            'subject' => $email->subject,
            'folder' => EmailThread::FOLDER_SENT,
            'unread_count' => 0,
            'last_message_at' => now(),
        ]);
    }
}
