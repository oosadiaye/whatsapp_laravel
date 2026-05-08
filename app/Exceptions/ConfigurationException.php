<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when required configuration is missing (e.g., AT virtual number
 * not set in /settings, API key missing). Caller surfaces as 503 with
 * actionable message pointing admin to /settings.
 */
class ConfigurationException extends \RuntimeException
{
}
