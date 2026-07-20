<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The provider-agnostic inbound-sync logic (plan B3): take a {@see MailFetcher}'s
 * output and persist it — dedup by message_id, thread by References/In-Reply-To,
 * store attachments on the private disk, advance the account's sync cursor. Pure
 * enough to fixture-test end-to-end with a stub fetcher (no live server).
 *
 * Threading is header-based only (References/In-Reply-To) — robust + deterministic
 * for standards-compliant mail. A reply that stripped its References headers
 * starts a new thread; a subject-similarity fallback is a documented future add.
 */
class EmailSyncService
{
    /**
     * @return int  number of NEW messages stored this run
     */
    public function sync(EmailAccount $account, MailFetcher $fetcher): int
    {
        $result = $fetcher->fetch($account);

        $new = 0;
        foreach ($result->messages as $message) {
            if ($this->store($account, $message)) {
                $new++;
            }
        }

        $account->update([
            'sync_state' => $result->newCursor,
            'last_synced_at' => now(),
            'needs_reauth' => false,
        ]);

        return $new;
    }

    private function store(EmailAccount $account, FetchedMessage $fetched): bool
    {
        $messageId = $this->normalizeId($fetched->messageId);
        if ($messageId === '') {
            return false; // no Message-ID → can't dedup; skip (malformed)
        }

        // Dedup per account — a re-fetch (incl. a full UIDVALIDITY re-sync) is a
        // safe no-op.
        $exists = EmailMessage::query()
            ->where('email_account_id', $account->id)
            ->where('message_id', $messageId)
            ->exists();
        if ($exists) {
            return false;
        }

        $thread = $this->resolveThread($account, $fetched);
        $receivedAt = $fetched->date ?? now();

        $message = $thread->messages()->create([
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'message_id' => $messageId,
            'in_reply_to' => $fetched->inReplyTo,
            'references_header' => $fetched->references,
            'from_email' => $fetched->from,
            'to' => $fetched->to,
            'cc' => $fetched->cc,
            'subject' => $fetched->subject,
            'body_html' => $fetched->bodyHtml,
            'body_text' => $fetched->bodyText,
            'is_read' => false,
            'has_attachments' => $fetched->attachments !== [],
            'received_at' => $receivedAt,
        ]);

        foreach ($fetched->attachments as $attachment) {
            $path = 'email-attachments/'.$account->id.'/'.Str::uuid()->toString().'-'.$attachment->filename;
            Storage::disk('local')->put($path, $attachment->content);

            $message->attachments()->create([
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size(),
                'path' => $path,
            ]);
        }

        $thread->update([
            'last_message_at' => $receivedAt,
            'unread_count' => $thread->unread_count + 1,
            'subject' => $thread->subject ?: $fetched->subject,
        ]);

        return true;
    }

    /**
     * Thread by header references: any already-stored message this one replies to
     * puts it in that thread; otherwise start a new one.
     */
    private function resolveThread(EmailAccount $account, FetchedMessage $fetched): EmailThread
    {
        $referencedIds = $this->referencedIds($fetched);

        if ($referencedIds !== []) {
            $parent = EmailMessage::query()
                ->where('email_account_id', $account->id)
                ->whereIn('message_id', $referencedIds)
                ->first();

            if ($parent !== null) {
                return $parent->thread;
            }
        }

        return EmailThread::create([
            'email_account_id' => $account->id,
            'subject' => $fetched->subject,
            'folder' => EmailThread::FOLDER_INBOX,
            'thread_ref' => $this->normalizeId($fetched->messageId),
            'last_message_at' => $fetched->date ?? now(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Normalized Message-IDs this message references (In-Reply-To + References),
     * matched against stored (also-normalized) message_ids.
     *
     * @return list<string>
     */
    private function referencedIds(FetchedMessage $fetched): array
    {
        $ids = [];

        if ($fetched->inReplyTo !== null && $fetched->inReplyTo !== '') {
            $ids[] = $fetched->inReplyTo;
        }

        if ($fetched->references !== null && preg_match_all('/<[^>]+>/', $fetched->references, $m)) {
            $ids = array_merge($ids, $m[0]);
        }

        $ids = array_map(fn (string $id): string => $this->normalizeId($id), $ids);

        return array_values(array_unique(array_filter($ids)));
    }

    private function normalizeId(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }
}
