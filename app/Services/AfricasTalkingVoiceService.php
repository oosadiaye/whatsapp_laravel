<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigurationException;
use App\Exceptions\VoiceProviderException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking Voice integration. Mirrors WhatsAppCloudApiService
 * shape: private client() helper, public methods wrapping the REST API.
 *
 * Outbound flow: agent click → CallController::placeOutbound() →
 * placeCall() → AT REST POST /call → AT dials the customer's PSTN phone →
 * on answer, AT requests the voice callback and AfricasTalkingWebhookController
 * returns <Dial agent_{id}> → AT bridges the customer to the agent's
 * registered WebRTC browser client → two-way audio.
 *
 * The browser client is registered once per page (window.bqVoiceClient,
 * booted from the layout) using a real capability token minted by
 * generateClientToken()/requestCapabilityToken() — NOT a locally-computed
 * HMAC. Without that token the africastalking-client SDK cannot register
 * or carry audio.
 */
class AfricasTalkingVoiceService
{
    public const API_BASE = 'https://voice.africastalking.com';

    /**
     * WebRTC capability-token endpoint. The browser SDK
     * (africastalking-client) cannot register or place/receive calls
     * without a capability token minted here — it is NOT a self-signed
     * JWT/HMAC, it must come from Africa's Talking.
     */
    public const WEBRTC_TOKEN_URL = 'https://webrtc.africastalking.com/capability-token/request';

    public function __construct(
        private readonly ContactImportService $normalizer,
    ) {}

    /**
     * Initiate an outbound PSTN call. Returns AT sessionId.
     *
     * @throws ConfigurationException Virtual number not configured.
     * @throws VoiceProviderException AT API failure or rejection.
     * @throws \InvalidArgumentException Phone number cannot be normalized to E.164.
     */
    public function placeCall(string $toCustomer): string
    {
        $virtual = Setting::get('africastalking_virtual_number');
        if ($virtual === null || $virtual === '') {
            throw new ConfigurationException("Africa's Talking virtual number not configured. Set in /settings.");
        }

        $normalized = $this->toE164($toCustomer);

        $response = $this->client()->asForm()->post(
            self::API_BASE.'/call',
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
            self::API_BASE.'/queueStatus',
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
     * Deterministic per-agent WebRTC client identifier. Africa's Talking
     * routes a call to a browser client by dialing this name in a
     * <Dial phoneNumbers="..."/> voice action, so it MUST match the
     * clientName the capability token was minted for. No spaces allowed
     * per AT's requirement — `agent_<id>` satisfies that.
     */
    public static function clientNameForUser(int $userId): string
    {
        return 'agent_'.$userId;
    }

    public function clientName(User $user): string
    {
        return self::clientNameForUser($user->id);
    }

    /**
     * Mint (and cache) a real Africa's Talking WebRTC capability token for
     * the agent's browser softphone. Without this token the
     * africastalking-client SDK can neither register nor make/receive
     * calls — it is issued by AT, not computed locally.
     *
     * Cached for 6h (AT default lifetime is 24h) keyed by clientName so a
     * page reload doesn't re-hit AT on every render.
     *
     * @throws ConfigurationException Missing API key / username / virtual number.
     * @throws VoiceProviderException AT rejected the token request.
     */
    public function generateClientToken(User $user): string
    {
        $apiKey = Setting::getEncrypted('africastalking_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException("Africa's Talking API key not configured.");
        }

        $username = (string) Setting::get('africastalking_username', '');
        $virtual = (string) Setting::get('africastalking_virtual_number', '');
        if ($username === '' || $virtual === '') {
            throw new ConfigurationException("Africa's Talking username / virtual number not configured.");
        }

        $clientName = $this->clientName($user);

        // Tests never reach AT — return a deterministic stub so feature
        // tests that render an authenticated layout (which mints a token)
        // make no network call. Mirrors the webhook's testing short-circuit.
        if (app()->environment('testing')) {
            return 'test-capability-token:'.$clientName;
        }

        return Cache::remember(
            'at_cap_token:'.$clientName,
            now()->addHours(6),
            fn (): string => $this->requestCapabilityToken($username, $clientName, $virtual, $apiKey),
        );
    }

    /**
     * POST to AT's capability-token endpoint and return the issued token.
     * Public so it can be exercised directly in tests without bypassing
     * the env('testing') short-circuit in {@see generateClientToken()}.
     *
     * @throws VoiceProviderException
     */
    public function requestCapabilityToken(
        string $username,
        string $clientName,
        string $phoneNumber,
        string $apiKey,
    ): string {
        $response = Http::withHeaders([
            'apiKey' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post(self::WEBRTC_TOKEN_URL, [
            'username' => $username,
            'clientName' => $clientName,
            'phoneNumber' => $phoneNumber,
            'incoming' => true,
            'outgoing' => true,
        ]);

        if ($response->failed()) {
            Log::error('AT capability-token HTTP failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new VoiceProviderException("capability-token HTTP {$response->status()}");
        }

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            Log::error('AT capability-token response missing token', ['body' => $response->body()]);
            throw new VoiceProviderException('AT capability-token response missing token');
        }

        return $token;
    }

    /**
     * Convert input phone to E.164 format (with leading '+'). Reuses
     * ContactImportService::normalizePhone (returns digits without '+');
     * this method prepends '+' for AT's E.164 requirement.
     *
     * @throws \InvalidArgumentException If input cannot be normalized.
     */
    private function toE164(string $input): string
    {
        $defaultCountryCode = (string) Setting::get('default_country_code', '234');
        $digits = $this->normalizer->normalizePhone($input, $defaultCountryCode);
        if ($digits === null) {
            throw new \InvalidArgumentException("Invalid phone number: {$input}");
        }

        return '+'.$digits;
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
