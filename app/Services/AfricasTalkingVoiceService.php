<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking Voice integration. Mirrors WhatsAppCloudApiService
 * shape: private client() helper, public methods wrapping the REST API.
 *
 * Outbound flow: agent click → CallController::placeOutbound() →
 * AfricasTalkingVoiceService::placeCall() → AT REST POST /call →
 * AT dials customer's PSTN phone → AT webhook arrives → audio
 * peer flows browser↔AT via JS SDK.
 *
 * The empty-SDP / pre-accept dance Phase 17 needed for Meta does NOT
 * apply here — AT handles ringing/connecting state internally and
 * we just react to webhook events.
 */
class AfricasTalkingVoiceService
{
    public const API_BASE = 'https://voice.africastalking.com';

    public function __construct(
        private readonly ContactImportService $normalizer,
    ) {
    }

    /**
     * Initiate an outbound PSTN call. Returns AT sessionId.
     *
     * @throws ConfigurationException  Virtual number not configured.
     * @throws VoiceProviderException  AT API failure or rejection.
     * @throws \InvalidArgumentException  Phone number cannot be normalized to E.164.
     */
    public function placeCall(string $toCustomer): string
    {
        $virtual = Setting::get('africastalking_virtual_number');
        if ($virtual === null || $virtual === '') {
            throw new ConfigurationException("Africa's Talking virtual number not configured. Set in /settings.");
        }

        $normalized = $this->toE164($toCustomer);

        $response = $this->client()->asForm()->post(
            self::API_BASE . '/call',
            [
                'username' => Setting::get('africastalking_username', ''),
                'from' => $virtual,
                'to' => $normalized,
            ],
        );

        if ($response->failed()) {
            Log::error('AT placeCall HTTP failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new VoiceProviderException("placeCall HTTP {$response->status()}");
        }

        $body = $response->json();
        $entry = $body['entries'][0] ?? null;
        if ($entry === null || ($entry['status'] ?? null) !== 'Queued') {
            $reason = $entry['status'] ?? ($body['errorMessage'] ?? 'unknown');
            throw new VoiceProviderException("AT rejected call: {$reason}");
        }

        return (string) $entry['sessionId'];
    }

    /**
     * Hang up an in-progress call by AT session ID. Best-effort —
     * 4xx/5xx are logged but do not throw because the call may have
     * already ended naturally.
     */
    public function endCall(string $sessionId): void
    {
        $response = $this->client()->asForm()->post(
            self::API_BASE . '/queueStatus',
            [
                'username' => Setting::get('africastalking_username', ''),
                'sessionId' => $sessionId,
                'action' => 'terminate',
            ],
        );

        if ($response->failed()) {
            Log::warning('AT endCall failure (swallowed)', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Generate a short-lived auth token for the JS SDK. Implementation
     * detail varies per AT SDK version — verify against their docs at
     * deploy time. Token scopes the agent identified by $user.
     */
    public function generateClientToken(User $user): string
    {
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException("Africa's Talking API key not configured.");
        }

        $username = (string) Setting::get('africastalking_username', '');
        $expiry = now()->addMinutes(60)->timestamp;
        $payload = "{$username}|{$user->id}|{$expiry}";

        return hash_hmac('sha256', $payload, $apiKey) . '.' . base64_encode($payload);
    }

    /**
     * Convert input phone to E.164 format (with leading '+'). Reuses
     * ContactImportService::normalizePhone (returns digits without '+');
     * this method prepends '+' for AT's E.164 requirement.
     *
     * @throws \InvalidArgumentException  If input cannot be normalized.
     */
    private function toE164(string $input): string
    {
        $defaultCountryCode = (string) Setting::get('default_country_code', '234');
        $digits = $this->normalizer->normalizePhone($input, $defaultCountryCode);
        if ($digits === null) {
            throw new \InvalidArgumentException("Invalid phone number: {$input}");
        }
        return '+' . $digits;
    }

    private function client(): PendingRequest
    {
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException("Africa's Talking API key not configured.");
        }

        return Http::withHeaders([
            'apiKey' => $apiKey,
            'Accept' => 'application/json',
        ]);
    }
}
