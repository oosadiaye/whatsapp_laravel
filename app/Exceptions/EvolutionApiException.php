<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception thrown when an Evolution API request fails.
 *
 * @deprecated Catch {@see WhatsAppApiException} instead — it covers every
 *             driver including Cloud API, Evolution, and any future addition.
 *             This subclass remains so existing catch blocks keep working
 *             without code changes; the dispatcher and Cloud service throw
 *             the parent type going forward.
 */
class EvolutionApiException extends WhatsAppApiException
{
}
