<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppInstance;

/**
 * Driver-agnostic facade for sending WhatsApp messages.
 *
 * Picks the right underlying client based on {@see WhatsAppInstance::$driver}:
 *   - 'cloud'     → {@see WhatsAppCloudApiService} (Meta Graph API)
 *   - 'evolution' → {@see EvolutionApiService} (legacy Baileys)
 *
 * Callers (jobs, controllers, future API endpoints) only ever talk to this
 * class — when the Evolution path is removed in Phase 5, only this dispatcher
 * needs to change, and every caller stays untouched.
 *
 * Returns a normalized {@see SendResult} so callers don't have to care about
 * the wildly different response shapes between Meta and Evolution.
 */
class WhatsAppMessenger
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloud,
        private readonly EvolutionApiService $evolution,
    ) {
    }

    /**
     * Send a freeform text message. For first contact (outside the 24h window)
     * use {@see sendTemplate()} instead — Cloud API will reject text otherwise.
     *
     * @throws WhatsAppApiException
     */
    public function sendText(WhatsAppInstance $instance, string $phone, string $message): SendResult
    {
        if ($instance->isCloud()) {
            $raw = $this->cloud->sendText($instance, $phone, $message);

            return new SendResult(
                messageId: $raw['messages'][0]['id'] ?? null,
                raw: $raw,
            );
        }

        $raw = $this->evolution->sendText($instance->instance_name, $phone, $message);

        return new SendResult(
            messageId: $raw['key']['id'] ?? null,
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
        if ($instance->isCloud()) {
            $raw = $this->cloud->sendMedia($instance, $phone, $mediaUrl, $mediaType, $caption);

            return new SendResult(
                messageId: $raw['messages'][0]['id'] ?? null,
                raw: $raw,
            );
        }

        $raw = $this->evolution->sendMedia(
            $instance->instance_name,
            $phone,
            $caption,
            $mediaUrl,
            $mediaType,
            $caption,
        );

        return new SendResult(
            messageId: $raw['key']['id'] ?? null,
            raw: $raw,
        );
    }

    /**
     * Send a Meta-approved template message. Only valid for cloud-driven
     * instances; throws for evolution instances.
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
        if (! $instance->isCloud()) {
            throw new WhatsAppApiException(
                'Template messages are only supported for Cloud API instances. '.
                'Evolution/Baileys instances must use sendText() or sendMedia().'
            );
        }

        $raw = $this->cloud->sendTemplate($instance, $phone, $templateName, $language, $components);

        return new SendResult(
            messageId: $raw['messages'][0]['id'] ?? null,
            raw: $raw,
        );
    }
}
