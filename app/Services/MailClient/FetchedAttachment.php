<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * A provider-agnostic attachment pulled from the server (plan B3). Carries the
 * raw bytes; EmailSyncService writes them to the private disk.
 */
final class FetchedAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $mime,
        public readonly string $content,
    ) {
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
