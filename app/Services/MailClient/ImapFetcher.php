<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Exceptions\MailAuthException;
use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

/**
 * IMAP inbound fetcher (plan B3). Incremental by UID within INBOX; a UIDVALIDITY
 * change invalidates the stored UID set and triggers a full re-sync (review M7).
 * `leaveUnread()` keeps the server's read state untouched during sync.
 *
 * This is the untestable-without-a-server I/O boundary — its webklex attribute
 * access needs LIVE verification against a real mailbox (plan B3 [user] step).
 * The sync LOGIC it feeds ({@see EmailSyncService}) is fixture-tested via the
 * {@see MailFetcher} interface, so a tweak here can't ripple.
 */
class ImapFetcher implements MailFetcher
{
    /** Cap messages processed per run so a huge/first sync advances over several passes. */
    private const PER_RUN_LIMIT = 200;

    public function __construct(private readonly ?ClientManager $clientManager = null)
    {
    }

    public function fetch(EmailAccount $account): FetchResult
    {
        $creds = $account->credentials ?? [];
        $state = $account->sync_state['inbox'] ?? [];
        $storedUidValidity = (int) ($state['uidvalidity'] ?? 0);
        $lastUid = (int) ($state['last_uid'] ?? 0);

        try {
            $client = $this->manager()->make([
                'host' => (string) ($creds['imap_host'] ?? ''),
                'port' => (int) ($creds['imap_port'] ?? 993),
                'encryption' => ($creds['imap_encryption'] ?? 'ssl') ?: false,
                'validate_cert' => (bool) ($creds['validate_cert'] ?? true),
                'username' => (string) ($creds['username'] ?? ''),
                'password' => (string) ($creds['password'] ?? ''),
                'protocol' => 'imap',
            ]);
            $client->connect();

            $folder = $client->getFolderByPath('INBOX');
            $currentUidValidity = (int) ($folder->examine()['uidvalidity'] ?? 0);

            // Cursor invalid (first run, or the mailbox re-numbered its UIDs) →
            // full re-sync from UID 0. Dedup downstream makes re-emitting safe.
            $fullResync = $storedUidValidity === 0 || $storedUidValidity !== $currentUidValidity;
            $fromUid = $fullResync ? 0 : $lastUid;

            $collection = $folder->query()
                ->leaveUnread()
                ->limit(self::PER_RUN_LIMIT)
                ->getByUidGreater($fromUid);

            $messages = [];
            // Start from 0 on a full re-sync: a UIDVALIDITY change renumbers the
            // mailbox, so the OLD last_uid is meaningless in the new UID space and
            // seeding maxUid with it could pin the cursor above the new (smaller)
            // UIDs, making the next incremental fetch skip everything below it.
            $maxUid = $fullResync ? 0 : $lastUid;
            foreach ($collection as $message) {
                try {
                    $uid = (int) $message->getUid();
                    $maxUid = max($maxUid, $uid);
                    $messages[] = $this->toFetchedMessage($message);
                } catch (\Throwable) {
                    // Skip a single unparseable message rather than fail the run.
                    continue;
                }
            }

            $client->disconnect();

            return new FetchResult(
                messages: $messages,
                newCursor: ['inbox' => ['uidvalidity' => $currentUidValidity, 'last_uid' => $maxUid]],
                wasFullResync: $fullResync,
            );
        } catch (AuthFailedException $e) {
            // Terminal — bad credentials. The job flags needs_reauth and stops.
            throw new MailAuthException($e->getMessage(), 0, $e);
        }
        // Other exceptions (connection/network) propagate → the job retries.
    }

    private function toFetchedMessage($message): FetchedMessage
    {
        return new FetchedMessage(
            messageId: (string) $message->getMessageId(),
            inReplyTo: $this->header($message->getInReplyTo()),
            references: $this->header($message->getReferences()),
            from: $this->firstAddress($message->getFrom()),
            to: $this->addressList($message->getTo()),
            cc: $this->addressList($message->getCc()),
            subject: $this->header($message->getSubject()),
            bodyHtml: ($html = (string) $message->getHTMLBody()) !== '' ? $html : null,
            bodyText: ($text = (string) $message->getTextBody()) !== '' ? $text : null,
            date: $this->date($message),
            attachments: $this->attachments($message),
            folder: 'inbox',
        );
    }

    private function header(mixed $attribute): ?string
    {
        try {
            $value = trim((string) $attribute);

            return $value === '' ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstAddress(mixed $addresses): string
    {
        foreach ($this->addressList($addresses) as $email) {
            return $email;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function addressList(mixed $addresses): array
    {
        $out = [];
        try {
            foreach ($addresses as $address) {
                $email = is_object($address) ? ($address->mail ?? (string) $address) : (string) $address;
                if ($email !== '') {
                    $out[] = (string) $email;
                }
            }
        } catch (\Throwable) {
            // ignore malformed address headers
        }

        return $out;
    }

    private function date(mixed $message): ?\DateTimeInterface
    {
        try {
            $date = $message->getDate();
            $carbon = is_object($date) && method_exists($date, 'toDate') ? $date->toDate() : null;

            return $carbon instanceof \DateTimeInterface ? $carbon : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<FetchedAttachment>
     */
    private function attachments(mixed $message): array
    {
        $out = [];
        try {
            foreach ($message->getAttachments() as $attachment) {
                $content = (string) $attachment->getContent();
                if ($content === '') {
                    continue;
                }
                $out[] = new FetchedAttachment(
                    filename: (string) ($attachment->getName() ?: 'attachment'),
                    mime: $attachment->getMimeType(),
                    content: $content,
                );
            }
        } catch (\Throwable) {
            // ignore attachment parse failures — the message body still syncs
        }

        return $out;
    }

    private function manager(): ClientManager
    {
        return $this->clientManager ?? new ClientManager();
    }
}
