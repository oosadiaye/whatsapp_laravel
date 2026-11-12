<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Exceptions\MailAuthException;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

/**
 * IMAP fetcher (plan B3): INBOX plus the Sent folder, each incremental by UID; a UIDVALIDITY
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
    /**
     * Cap messages per pass so a huge first sync advances in batches that fit the job timeout;
     * SyncEmailAccount re-queues itself while {@see FetchResult::$hasMore} is true.
     */
    private const PER_RUN_LIMIT = 50;

    public function __construct(private readonly ?ClientManager $clientManager = null) {}

    public function fetch(EmailAccount $account): FetchResult
    {
        $creds = $account->credentials ?? [];
        $state = $account->sync_state ?? [];

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

            $inbox = $this->pull($client->getFolderByPath('INBOX'), (array) ($state['inbox'] ?? []), 'inbox', $account);

            $messages = $inbox['messages'];
            $cursor = ['inbox' => $inbox['cursor']];
            $hasMore = $inbox['hasMore'];

            // Mail sent from elsewhere (phone, webmail) and the Sent history. Best effort: an
            // unusual or unreadable Sent folder must never stop the inbox from syncing, so on any
            // failure keep the previous Sent cursor and try again next pass.
            try {
                $sent = $this->pullSent($client, (array) ($state['sent'] ?? []), $account);
                if ($sent !== null) {
                    $messages = array_merge($messages, $sent['messages']);
                    $cursor['sent'] = $sent['cursor'];
                    $hasMore = $hasMore || $sent['hasMore'];
                }
            } catch (AuthFailedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Sent folder sync failed; inbox unaffected', ['account_id' => $account->id, 'error' => $e->getMessage()]);
                if (isset($state['sent'])) {
                    $cursor['sent'] = $state['sent'];
                }
            }

            $client->disconnect();

            return new FetchResult(
                messages: $messages,
                newCursor: $cursor,
                wasFullResync: $inbox['fullResync'],
                hasMore: $hasMore,
            );
        } catch (AuthFailedException $e) {
            // Terminal — bad credentials. The job flags needs_reauth and stops.
            throw new MailAuthException($e->getMessage(), 0, $e);
        }
        // Other exceptions (connection/network) propagate → the job retries.
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{messages: list<FetchedMessage>, cursor: array<string, int>, hasMore: bool}|null
     */
    private function pullSent(mixed $client, array $state, EmailAccount $account): ?array
    {
        $byPath = [];
        foreach ($client->getFolders(false) as $folder) {
            $byPath[(string) $folder->path] = $folder;
        }

        $path = SentFolderFinder::pick(array_keys($byPath));
        if ($path === null) {
            return null;
        }

        $pulled = $this->pull($byPath[$path], $state, 'sent', $account);

        return ['messages' => $pulled['messages'], 'cursor' => $pulled['cursor'], 'hasMore' => $pulled['hasMore']];
    }

    /**
     * One pass over one folder (see {@see UidCursor}). The first pass, or a renumbered folder
     * (UIDVALIDITY change), takes the NEWEST batch so recent mail shows up at once; after that each
     * pass takes mail newer than the last one, and then one batch of older history until the start
     * of the folder is reached. Dedup downstream makes re-emitting safe.
     *
     * @param  array<string, mixed>  $state  {uidvalidity, last_uid, backfill_below} from the previous pass
     * @return array{messages: list<FetchedMessage>, cursor: array<string, int>, fullResync: bool, hasMore: bool}
     */
    private function pull(mixed $folder, array $state, string $label, EmailAccount $account): array
    {
        $currentUidValidity = (int) ($folder->examine()['uidvalidity'] ?? 0);
        $fullResync = UidCursor::needsFullResync($state, $currentUidValidity);

        if ($fullResync) {
            $newest = $this->readBatch(
                $folder->query()->leaveUnread()->setFetchOrderDesc()->limit(self::PER_RUN_LIMIT)->getByUidGreater(0),
                $label,
                $account,
            );
            $cursor = UidCursor::afterNewest($currentUidValidity, $newest['uids'], self::PER_RUN_LIMIT);

            return [
                'messages' => $newest['messages'],
                'cursor' => $cursor,
                'fullResync' => true,
                'hasMore' => UidCursor::hasMore($cursor, 0, self::PER_RUN_LIMIT),
            ];
        }

        $lastUid = (int) ($state['last_uid'] ?? 0);
        $below = (int) ($state['backfill_below'] ?? 0);

        // Mail that arrived since last time, oldest first so a cut-off batch never skips any.
        $new = $this->readBatch(
            $folder->query()->leaveUnread()->setFetchOrderAsc()->limit(self::PER_RUN_LIMIT)->getByUidGreater($lastUid),
            $label,
            $account,
        );

        // History, newest of the unread part first, only once new mail is fully caught up.
        $older = ['messages' => [], 'uids' => []];
        if ($below > 1 && count($new['uids']) < self::PER_RUN_LIMIT) {
            $older = $this->readBatch(
                $folder->query()->leaveUnread()->setFetchOrderDesc()->limit(self::PER_RUN_LIMIT)->getByUidLower($below),
                $label,
                $account,
            );
        }

        $cursor = UidCursor::afterIncremental($state, $currentUidValidity, $new['uids'], $older['uids'], self::PER_RUN_LIMIT);

        return [
            'messages' => array_merge($new['messages'], $older['messages']),
            'cursor' => $cursor,
            'fullResync' => false,
            'hasMore' => UidCursor::hasMore($cursor, count($new['uids']), self::PER_RUN_LIMIT),
        ];
    }

    /**
     * Turn one fetched batch into messages. Every UID is reported, even for a message skipped as
     * oversized or unreadable, so the cursor still moves past it.
     *
     * @return array{messages: list<FetchedMessage>, uids: list<int>}
     */
    private function readBatch(iterable $collection, string $label, EmailAccount $account): array
    {
        $messages = [];
        $uids = [];

        foreach ($collection as $message) {
            try {
                $uid = (int) $message->getUid();
                $uids[] = $uid;

                // Skip oversized mail BEFORE materialising its bodies / attachments — the memory +
                // private-disk DoS guard. The deterministic layer lives in EmailSyncService::store.
                $maxBytes = (int) config('mail_client.max_inbound_kb', 25600) * 1024;
                if ($maxBytes > 0) {
                    try {
                        if ((int) $message->getSize() > $maxBytes) {
                            Log::warning('Skipping oversized message', ['account_id' => $account->id, 'uid' => $uid, 'folder' => $label, 'cap_bytes' => $maxBytes]);

                            continue;
                        }
                    } catch (\Throwable) {
                        // size unreadable — don't skip on a missing header
                    }
                }

                $messages[] = $this->toFetchedMessage($message, $label);
            } catch (\Throwable) {
                // Skip a single unparseable message rather than fail the run.
                continue;
            }
        }

        return ['messages' => $messages, 'uids' => $uids];
    }

    private function toFetchedMessage($message, string $folder): FetchedMessage
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
            folder: $folder,
            isRead: $this->isSeen($message),
        );
    }

    /** Mail already read elsewhere stays read here; an unreadable flag set means unread. */
    private function isSeen(mixed $message): bool
    {
        try {
            return (bool) $message->getFlags()->has('seen');
        } catch (\Throwable) {
            return false;
        }
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
        return $this->clientManager ?? new ClientManager;
    }
}
