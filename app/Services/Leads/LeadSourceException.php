<?php

declare(strict_types=1);

namespace App\Services\Leads;

use RuntimeException;
use Throwable;

/**
 * A platform call failed. `retryable` tells the puller whether trying again later
 * can help (rate limit, outage) or whether an admin must fix something (bad key).
 */
final class LeadSourceException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
