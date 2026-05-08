<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a voice provider's API call fails (5xx, 4xx, network).
 * Caller layer (CallController) catches this to return 503 to the agent
 * with a "Voice service unavailable" message.
 */
class VoiceProviderException extends \RuntimeException
{
}
