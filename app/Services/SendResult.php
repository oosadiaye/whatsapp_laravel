<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalized response from a successful message send across all drivers.
 *
 * Cloud API returns: { messaging_product: "whatsapp", contacts: [...], messages: [{ id: "wamid.xxx" }] }
 * Evolution API returns: { key: { id: "...", remoteJid: "...", fromMe: true }, ... }
 *
 * The dispatcher flattens both into this DTO so callers don't have to know
 * which driver produced the response.
 */
final readonly class SendResult
{
    /**
     * @param  string|null  $messageId  Provider-issued message ID (wamid.xxx for Cloud, key.id for Evolution).
     *                                  null only when the provider didn't return one — treat as a soft warning.
     * @param  array<string, mixed>  $raw  Full provider response, kept for debugging and message-log enrichment.
     */
    public function __construct(
        public ?string $messageId,
        public array $raw,
    ) {
    }
}
