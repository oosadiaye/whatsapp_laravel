<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EvolutionApiException;
use App\Models\WhatsAppInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for Meta's WhatsApp Cloud API.
 *
 * All credentials live on the {@see WhatsAppInstance} model — every method takes
 * the instance as its first argument so that the same service handles many
 * customers/numbers without re-binding singletons.
 *
 * Reuses {@see EvolutionApiException} for now to keep error handling consistent
 * across the codebase; renaming to a neutral "WhatsAppApiException" can happen
 * after the Evolution path is removed in a later cleanup pass.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class WhatsAppCloudApiService
{
    /**
     * Graph API version. Bump deliberately — Meta deprecates versions roughly
     * every two years and behaviour can change between them.
     */
    public const GRAPH_API_VERSION = 'v20.0';

    private const BASE_URL = 'https://graph.facebook.com';

    /**
     * Build an HTTP client pre-configured with the instance's bearer token.
     */
    private function client(WhatsAppInstance $instance): PendingRequest
    {
        if (! $instance->isCloudReady()) {
            throw new EvolutionApiException(
                "Instance {$instance->id} is missing Cloud API credentials (waba_id / phone_number_id / access_token)."
            );
        }

        return Http::withToken((string) $instance->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }

    private function url(string $path): string
    {
        return self::BASE_URL.'/'.self::GRAPH_API_VERSION.'/'.ltrim($path, '/');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Sending
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Send a freeform text message. Only legal inside the 24-hour conversation
     * window — for first contact use {@see sendTemplate()} instead.
     *
     * @return array{messages: array<int, array{id: string}>}|array<string, mixed>
     *
     * @throws EvolutionApiException
     */
    public function sendText(WhatsAppInstance $instance, string $phone, string $message): array
    {
        return $this->postMessage($instance, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    /**
     * Send a media message (image/document/audio/video).
     *
     * @throws EvolutionApiException
     */
    public function sendMedia(
        WhatsAppInstance $instance,
        string $phone,
        string $mediaUrl,
        string $mediaType,
        string $caption = '',
    ): array {
        $type = strtolower($mediaType);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => $type,
            $type => array_filter([
                'link' => $mediaUrl,
                'caption' => in_array($type, ['image', 'video', 'document'], true) ? $caption : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        return $this->postMessage($instance, $payload);
    }

    /**
     * Send a Meta-approved template message. This is the only message type
     * allowed outside the 24-hour conversation window.
     *
     * @param  array<int, array<string, mixed>>  $components  body/header/button parameter substitutions
     *
     * @throws EvolutionApiException
     */
    public function sendTemplate(
        WhatsAppInstance $instance,
        string $phone,
        string $templateName,
        string $language = 'en_US',
        array $components = [],
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($phone),
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ], fn ($v) => $v !== null),
        ];

        return $this->postMessage($instance, $payload);
    }

    /**
     * Mark an inbound message as read so the user sees the blue ticks.
     */
    public function markAsRead(WhatsAppInstance $instance, string $messageId): array
    {
        return $this->postMessage($instance, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Templates
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * List all message templates for the WABA. Honors Meta's pagination by
     * following `paging.next` until exhausted.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws EvolutionApiException
     */
    public function fetchTemplates(WhatsAppInstance $instance, int $limit = 100): array
    {
        $all = [];
        $url = $this->url("{$instance->waba_id}/message_templates").'?limit='.$limit;

        do {
            $response = $this->client($instance)->get($url);

            if ($response->failed()) {
                $this->logHttp('fetchTemplates', $instance, $response->status(), $response->body());

                throw new EvolutionApiException(
                    "Failed to fetch templates: {$response->status()} - {$response->body()}"
                );
            }

            $body = $response->json();
            $all = array_merge($all, $body['data'] ?? []);

            $url = $body['paging']['next'] ?? null;
        } while ($url !== null);

        return $all;
    }

    /**
     * Submit a new template to Meta for review.
     *
     * @param  array<int, array<string, mixed>>  $components  e.g. [['type' => 'BODY', 'text' => 'Hello {{1}}']]
     *
     * @throws EvolutionApiException
     */
    public function createTemplate(
        WhatsAppInstance $instance,
        string $name,
        string $category,
        string $language,
        array $components,
    ): array {
        $response = $this->client($instance)->post(
            $this->url("{$instance->waba_id}/message_templates"),
            [
                'name' => $name,
                'category' => strtoupper($category), // MARKETING / UTILITY / AUTHENTICATION
                'language' => $language,
                'components' => $components,
            ],
        );

        if ($response->failed()) {
            $this->logHttp('createTemplate', $instance, $response->status(), $response->body());

            throw new EvolutionApiException(
                "Failed to create template: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Delete a template by name (Meta also accepts hsm_id for stricter targeting).
     *
     * @throws EvolutionApiException
     */
    public function deleteTemplate(WhatsAppInstance $instance, string $name): array
    {
        $response = $this->client($instance)->delete(
            $this->url("{$instance->waba_id}/message_templates"),
            ['name' => $name],
        );

        if ($response->failed()) {
            $this->logHttp('deleteTemplate', $instance, $response->status(), $response->body());

            throw new EvolutionApiException(
                "Failed to delete template: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phone-number / health
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Pull number metadata (display name, quality rating, throughput tier).
     * Used both as a credential validity check on instance setup and for
     * periodic health refresh.
     *
     * @throws EvolutionApiException
     */
    public function getPhoneNumberInfo(WhatsAppInstance $instance): array
    {
        $response = $this->client($instance)->get(
            $this->url($instance->phone_number_id),
            ['fields' => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier,name_status,code_verification_status'],
        );

        if ($response->failed()) {
            $this->logHttp('getPhoneNumberInfo', $instance, $response->status(), $response->body());

            throw new EvolutionApiException(
                "Failed to fetch phone number info: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postMessage(WhatsAppInstance $instance, array $payload): array
    {
        $response = $this->client($instance)->post(
            $this->url("{$instance->phone_number_id}/messages"),
            $payload,
        );

        if ($response->failed()) {
            $this->logHttp('postMessage', $instance, $response->status(), $response->body());

            throw new EvolutionApiException(
                "Cloud API send failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Strip leading +, spaces, and dashes — Cloud API wants pure digits in E.164 form.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    private function logHttp(string $op, WhatsAppInstance $instance, int $status, string $body): void
    {
        Log::error("WhatsAppCloudApi {$op} failed", [
            'instance_id' => $instance->id,
            'phone_number_id' => $instance->phone_number_id,
            'status' => $status,
            'body' => $body,
        ]);
    }
}
