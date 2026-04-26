<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;

/**
 * Thin facade over {@see WhatsAppCloudApiService} that normalizes provider
 * responses into a {@see SendResult} DTO.
 *
 * Originally a multi-driver dispatcher (Cloud + Evolution), now Cloud-only.
 * Kept as a separate class — rather than collapsing into the Cloud service —
 * so callers consume one stable interface and we can later layer in:
 *   - voice messages / calling beta when Meta exposes the Calling API publicly
 *   - other text-channel adapters (none planned today)
 * without modifying every send site.
 */
class WhatsAppMessenger
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloud,
    ) {
    }

    /**
     * Send a freeform text message. Only legal inside a 24-hour conversation
     * window — for first contact / cold outreach use {@see sendTemplate()}.
     *
     * @throws WhatsAppApiException
     */
    public function sendText(WhatsAppInstance $instance, string $phone, string $message): SendResult
    {
        $raw = $this->cloud->sendText($instance, $phone, $message);

        return new SendResult(
            messageId: $raw['messages'][0]['id'] ?? null,
            raw: $raw,
        );
    }

    /**
     * Send a media message (image/document/audio/video) with optional caption.
     *
     * @throws WhatsAppApiException
     */
    public function sendMedia(
        WhatsAppInstance $instance,
        string $phone,
        string $caption,
        string $mediaUrl,
        string $mediaType,
    ): SendResult {
        $raw = $this->cloud->sendMedia($instance, $phone, $mediaUrl, $mediaType, $caption);

        return new SendResult(
            messageId: $raw['messages'][0]['id'] ?? null,
            raw: $raw,
        );
    }

    /**
     * Send a Meta-approved template message. Required for any outreach outside
     * the 24-hour conversation window — i.e. all marketing campaigns to fresh
     * contacts.
     *
     * @param  array<int, array<string, mixed>>  $components  Body/header/button parameter arrays
     *
     * @throws WhatsAppApiException
     */
    public function sendTemplate(
        WhatsAppInstance $instance,
        string $phone,
        string $templateName,
        string $language,
        array $components = [],
    ): SendResult {
        $raw = $this->cloud->sendTemplate($instance, $phone, $templateName, $language, $components);

        return new SendResult(
            messageId: $raw['messages'][0]['id'] ?? null,
            raw: $raw,
        );
    }
}
