<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a call to Meta's WhatsApp Cloud API fails.
 *
 * Carries the upstream HTTP status and response body in its message so the
 * caller can decide whether to retry, surface to the user, or escalate.
 */
class WhatsAppApiException extends RuntimeException
{
}
