<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * Result of a mail-account connection attempt (plan B2). Carries the failure
 * reason so the connect UI can tell the user WHY their credentials were rejected.
 */
final class ConnectionResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }
}
