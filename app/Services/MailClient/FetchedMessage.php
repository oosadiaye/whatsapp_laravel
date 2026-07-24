<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use DateTimeInterface;

/**
 * A normalized inbound message from any provider (plan B3). The fetcher produces
 * these; EmailSyncService dedups, threads, and persists them — so the sync logic
 * never touches provider-specific shapes.
 */
final class FetchedMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<FetchedAttachment>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly string $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly ?string $subject,
        public readonly ?string $bodyHtml,
        public readonly ?string $bodyText,
        public readonly ?DateTimeInterface $date,
        public readonly array $attachments = [],
        public readonly string $folder = 'inbox',
    ) {
    }
}
