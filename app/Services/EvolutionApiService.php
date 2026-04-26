<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EvolutionApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps all HTTP calls to the Evolution API.
 */
class EvolutionApiService
{
    protected string $baseUrl;

    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(Setting::get('evolution_api_url', 'http://localhost:8080'), '/');
        $this->apiKey = Setting::get('evolution_api_key', '');
    }

    /**
     * Send a text message via Evolution API.
     *
     * @throws EvolutionApiException
     */
    public function sendText(string $instance, string $phone, string $message): array
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->post("{$this->baseUrl}/message/sendText/{$instance}", [
                'number' => $phone,
                'text' => $message,
            ]);

        if ($response->failed()) {
            Log::error('EvolutionAPI sendText failed', [
                'instance' => $instance,
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to send text message: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Send a media message via Evolution API.
     *
     * @throws EvolutionApiException
     */
    public function sendMedia(
        string $instance,
        string $phone,
        string $message,
        string $mediaUrl,
        string $mediaType,
        string $caption = '',
    ): array {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->post("{$this->baseUrl}/message/sendMedia/{$instance}", [
                'number' => $phone,
                'caption' => $caption ?: $message,
                'media' => $mediaUrl,
                'mediatype' => $mediaType,
                'mimetype' => $this->getMimeType($mediaType),
            ]);

        if ($response->failed()) {
            Log::error('EvolutionAPI sendMedia failed', [
                'instance' => $instance,
                'phone' => $phone,
                'mediaType' => $mediaType,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to send media message: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Get the connection status of a WhatsApp instance.
     *
     * @throws EvolutionApiException
     */
    public function getInstanceStatus(string $instance): string
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->get("{$this->baseUrl}/instance/fetchInstances");

        if ($response->failed()) {
            Log::error('EvolutionAPI fetchInstances failed', [
                'instance' => $instance,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to fetch instances: {$response->status()} - {$response->body()}"
            );
        }

        $instances = $response->json();

        foreach ($instances as $item) {
            if (($item['instance']['instanceName'] ?? '') === $instance) {
                return $item['instance']['state'] ?? 'unknown';
            }
        }

        return 'not_found';
    }

    /**
     * Get the QR code for connecting a WhatsApp instance.
     *
     * @throws EvolutionApiException
     */
    public function getQrCode(string $instance): ?string
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->get("{$this->baseUrl}/instance/connect/{$instance}");

        if ($response->failed()) {
            Log::error('EvolutionAPI getQrCode failed', [
                'instance' => $instance,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to get QR code: {$response->status()} - {$response->body()}"
            );
        }

        $data = $response->json();

        return $data['base64'] ?? null;
    }

    /**
     * Create a new WhatsApp instance.
     *
     * @throws EvolutionApiException
     */
    public function createInstance(string $instanceName): array
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->post("{$this->baseUrl}/instance/create", [
                'instanceName' => $instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);

        if ($response->failed()) {
            Log::error('EvolutionAPI createInstance failed', [
                'instanceName' => $instanceName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to create instance: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Delete a WhatsApp instance.
     *
     * @throws EvolutionApiException
     */
    public function deleteInstance(string $instance): array
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->delete("{$this->baseUrl}/instance/delete/{$instance}");

        if ($response->failed()) {
            Log::error('EvolutionAPI deleteInstance failed', [
                'instance' => $instance,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to delete instance: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Set the webhook URL and events for a WhatsApp instance.
     *
     * @param  array<string>  $events
     *
     * @throws EvolutionApiException
     */
    public function setWebhook(
        string $instance,
        string $url,
        array $events = ['MESSAGES_UPDATE', 'MESSAGES_UPSERT'],
    ): array {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->post("{$this->baseUrl}/webhook/set/{$instance}", [
                'url' => $url,
                'events' => $events,
                'webhook_base64' => false,
            ]);

        if ($response->failed()) {
            Log::error('EvolutionAPI setWebhook failed', [
                'instance' => $instance,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to set webhook: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Fetch all WhatsApp Business templates registered against this instance.
     *
     * Requires the Evolution API instance to be connected via the WhatsApp Cloud
     * API integration (Baileys/Web instances will return an empty list because
     * the underlying protocol does not expose Meta-approved templates).
     *
     * @return array<int, array<string, mixed>> Raw template payload from Evolution API.
     *
     * @throws EvolutionApiException
     */
    public function fetchTemplates(string $instance): array
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->get("{$this->baseUrl}/template/find/{$instance}");

        if ($response->failed()) {
            Log::error('EvolutionAPI fetchTemplates failed', [
                'instance' => $instance,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to fetch templates: {$response->status()} - {$response->body()}"
            );
        }

        $data = $response->json();

        // Evolution API may return either a flat array or { data: [...] }.
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Send a Meta-approved template message via Evolution API (Cloud API integration).
     *
     * @param  array<int, array<string, mixed>>  $components  e.g. body parameter substitutions
     *
     * @throws EvolutionApiException
     */
    public function sendTemplate(
        string $instance,
        string $phone,
        string $templateName,
        string $language = 'en_US',
        array $components = [],
    ): array {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->post("{$this->baseUrl}/message/sendTemplate/{$instance}", [
                'number' => $phone,
                'name' => $templateName,
                'language' => $language,
                'components' => $components,
            ]);

        if ($response->failed()) {
            Log::error('EvolutionAPI sendTemplate failed', [
                'instance' => $instance,
                'phone' => $phone,
                'template' => $templateName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new EvolutionApiException(
                "Failed to send template: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Map a media type string to its corresponding MIME type.
     */
    private function getMimeType(string $mediaType): string
    {
        return match ($mediaType) {
            'image' => 'image/jpeg',
            'document' => 'application/pdf',
            'audio' => 'audio/mpeg',
            default => 'application/octet-stream',
        };
    }
}
