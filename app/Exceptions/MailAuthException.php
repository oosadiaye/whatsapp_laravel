<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A mail account's stored credentials no longer authenticate (plan B3). Terminal
 * — the sync job flags the account needs_reauth and stops, rather than retrying
 * (a retry can't fix bad credentials). Distinct from transient/network errors,
 * which propagate and DO retry with backoff.
 */
class MailAuthException extends RuntimeException
{
}
