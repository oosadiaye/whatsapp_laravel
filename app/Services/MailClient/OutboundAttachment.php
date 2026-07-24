<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * One attachment to send (plan B5a). Referenced by a path on the private
 * `local` disk — NOT raw bytes — so the whole {@see OutboundEmail} stays small
 * enough to ride the queue payload. The composer (B5b) stages uploads to disk
 * and hands the path here.
 */
final class OutboundAttachment
{
    public function __construct(
        public readonly string $diskPath,
        public readonly string $filename,
        public readonly string $mime = 'application/octet-stream',
    ) {}

    /**
     * @param  array{disk_path: string, filename: string, mime?: string}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            diskPath: (string) $payload['disk_path'],
            filename: (string) $payload['filename'],
            mime: (string) ($payload['mime'] ?? 'application/octet-stream'),
        );
    }
}
