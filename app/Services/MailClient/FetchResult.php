<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * The outcome of one fetch pass (plan B3): the messages pulled, the new sync
 * cursor to persist, and whether the provider cursor was invalid and forced a
 * full re-sync (IMAP UIDVALIDITY change / Gmail historyId 410 / Graph delta
 * expiry). A full re-sync re-emits already-seen messages; the service's
 * dedup-by-message_id makes that a safe no-op (review M7).
 */
final class FetchResult
{
    /**
     * @param  list<FetchedMessage>  $messages
     * @param  array<string, mixed>  $newCursor  persisted to EmailAccount.sync_state
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $newCursor,
        public readonly bool $wasFullResync = false,
    ) {
    }
}
