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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Send one message from a connected mailbox — reply or fresh compose (plan B5a).
 *
 * `$tries = 1` ON PURPOSE (the inverse of {@see SyncEmailAccount}): a send has no
 * idempotency key on the wire, so a retry would deliver the message a SECOND time.
 * A transient SMTP hiccup surfaces as a failed job the user can resend, never a
 * silent double-send. The sent copy is stored locally so it appears in the thread
 * immediately (re-sync later dedupes it by the Message-ID we generate here).
 *
 * The per-account rate limiter is the ONE place a retry is safe (nothing has been
 * sent yet), so it uses release() — and {@see retryUntil()} gives that release a
 * window to retry in, without which a released $tries=1 job would be marked
 * permanently failed and the email silently dropped. The actual send is guarded
 * by try/catch → {@see fail()} so a real send failure still never retries.
 */
class SendUserEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Retry window for the throttle-release path ONLY. A hard send failure
     * short-circuits via $this->fail() and never gets here, so this can't turn
     * a failed send into a double-send. While retryUntil() is set and unexpired,
     * Laravel ignores $tries — that's exactly what lets the throttle release
     * retry instead of dying on attempt 2.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

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

        $rateKey = 'email-send:'.$account->id;
        $maxPerMinute = (int) config('mail_client.send_rate_per_minute', 30);
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerMinute)) {
            // Over the per-account rate. DEFER — nothing has been sent yet, so a
            // retry is safe. release() (not drop): retryUntil() keeps this from
            // becoming a permanent failure despite $tries = 1.
            $this->release(RateLimiter::availableIn($rateKey) ?: 30);

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $sender = $factory->senderFor($account);
        if ($sender === null) {
            return;
        }

        // Stamp a stable Message-ID before sending so the sent header and the
        // stored copy match (and a re-synced Sent-folder copy dedupes cleanly).
        $email = $this->email->withMessageId($this->generateMessageId($account));

        try {
            $sender->send($account, $email);
        } catch (\Throwable $e) {
            // A hard send failure must NOT retry: there's no idempotency key on
            // the wire, so a retry could deliver a SECOND copy. Fail permanently
            // (and audibly) rather than releasing back into the retry window.
            $this->fail($e);

            return;
        }

        // The send already SUCCEEDED — the recipient has the email. A failure now
        // storing the local copy must NOT re-enter handle(): retryUntil() overrides
        // $tries=1, so an uncaught throw here would re-queue the job and RE-SEND a
        // second copy. Swallow it — the sent copy is best-effort and a later inbox
        // re-sync dedupes it by the Message-ID stamped above.
        try {
            $this->storeSentCopy($account, $email);
        } catch (\Throwable $e) {
            Log::warning('SendUserEmail: send succeeded but storing the local sent copy failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Surface a permanently-failed send so a dropped email is never silent — the
     * operator can see it in the log and the user can resend.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('SendUserEmail failed', [
            'account_id' => $this->accountId,
            'error' => $e->getMessage(),
        ]);
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
