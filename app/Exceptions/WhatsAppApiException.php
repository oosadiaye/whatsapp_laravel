<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Driver-agnostic exception for any WhatsApp provider failure (Cloud API,
 * Evolution, future drivers like Twilio/Vonage/360dialog).
 *
 * Use this as the base type when catching provider errors so callers don't
 * need to know which underlying driver raised it. The legacy
 * {@see EvolutionApiException} now extends this for backward compatibility —
 * existing `catch (EvolutionApiException $e)` blocks keep working, and new
 * code can `catch (WhatsAppApiException $e)` to handle errors from any driver.
 */
class WhatsAppApiException extends RuntimeException
{
}
