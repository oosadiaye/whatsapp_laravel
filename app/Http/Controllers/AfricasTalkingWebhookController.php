<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Calling\CallRinging;
use App\Events\Calling\CallTerminated;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\WhatsAppInstance;
use App\Services\RoundRobinAssigner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles webhook events from Africa's Talking voice. Both outbound
 * lifecycle (Ringing/InProgress/Completed/Failed) and inbound first
 * events (customer dialing the virtual number).
 *
 * Single-tenant: inbound events attach to whichever WhatsAppInstance
 * is the org's primary (first record). Multi-tenant scoping is
 * explicitly NOT supported (per spec).
 */
class AfricasTalkingWebhookController extends Controller
{
    public function __construct(
        private readonly RoundRobinAssigner $assigner,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$this->verifySignature($request)) {
            return response('invalid signature', 401);
        }

        $event = $request->all();
        $sessionId = $event['sessionId'] ?? null;
        $direction = strtolower($event['direction'] ?? '');
        $status = $event['status'] ?? null;

        // Inbound first event — no prior CallLog. Create the chain.
        if ($direction === 'inbound') {
            $existing = CallLog::where('provider_session_id', $sessionId)->first();
            if ($existing === null) {
                return $this->handleInboundFirstEvent($event);
            }
        }

        $call = CallLog::where('provider_session_id', $sessionId)->first();
        if ($call === null) {
            Log::warning('AT webhook for unknown sessionId', ['session_id' => $sessionId]);
            return response('ok', 200);
        }

        match ($status) {
            'Ringing' => $call->update(['status' => CallLog::STATUS_RINGING]),
            'InProgress' => $call->update([
                'status' => CallLog::STATUS_CONNECTED,
                'connected_at' => $call->connected_at ?? now(),
            ]),
            'Completed' => $this->finalizeCall($call, $event, CallLog::STATUS_ENDED),
            'Failed' => $this->finalizeCall($call, $event, CallLog::STATUS_FAILED),
            default => null,
        };

        if (in_array($status, ['Completed', 'Failed'], true)) {
            CallTerminated::dispatch($call->fresh(), 'remote_' . strtolower($status));
        }

        return response('ok', 200);
    }

    private function handleInboundFirstEvent(array $event): Response
    {
        $callerPhone = $event['callerNumber'] ?? null;
        if ($callerPhone === null) {
            Log::warning('AT inbound webhook missing callerNumber', $event);
            return response('ok', 200);
        }

        $instance = WhatsAppInstance::query()->orderBy('id')->first();
        if ($instance === null) {
            Log::warning('AT inbound but no WhatsAppInstance configured');
            return response('ok', 200);
        }

        // Strip leading + for storage match (Contact.phone is stored without +
        // per ContactImportService::normalizePhone convention).
        $phoneDigits = ltrim($callerPhone, '+');

        $contact = Contact::firstOrCreate(
            [
                'user_id' => $instance->user_id,
                'phone' => $phoneDigits,
            ],
            ['name' => null, 'is_active' => true],
        );

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );

        if ($conversation->assigned_to_user_id === null) {
            $agent = $this->assigner->next();
            if ($agent !== null) {
                $conversation->update(['assigned_to_user_id' => $agent->id]);
            }
        }

        $call = CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => $event['sessionId'] ?? null,
            'status' => CallLog::STATUS_RINGING,
            'started_at' => now(),
            'from_phone' => $callerPhone,
            'to_phone' => $event['destinationNumber'] ?? '',
        ]);

        $conversation->refresh();
        if ($conversation->assigned_to_user_id !== null) {
            CallRinging::dispatch($call);
        }

        return response('ok', 200);
    }

    private function finalizeCall(CallLog $call, array $event, string $endStatus): void
    {
        $duration = (int) ($event['durationInSeconds'] ?? 0);
        $rateKobo = (int) Setting::get('africastalking_rate_per_minute_kobo', 600);
        $costKobo = (int) ceil($duration * $rateKobo / 60);

        $update = [
            'status' => $endStatus,
            'ended_at' => now(),
            'duration_seconds' => $duration,
            'cost_estimate_kobo' => $costKobo,
        ];

        if ($endStatus === CallLog::STATUS_FAILED) {
            $cause = $event['hangupCause'] ?? 'AT_FAILED';
            $update['failure_reason'] = "AT failure: {$cause}";
        }

        $call->update($update);
    }

    /**
     * Verify HMAC signature on incoming AT webhook. Short-circuits true
     * in the test environment so PHPUnit doesn't need to forge real
     * signatures. Production behavior: verify HMAC-SHA256 of raw body
     * against the AT API key as shared secret, in constant time.
     *
     * Exact header name per AT docs — verify at deploy time.
     */
    private function verifySignature(Request $request): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $signature = $request->header('X-Africastalking-Signature');
        if ($signature === null) {
            return false;
        }

        $apiKey = Setting::getEncrypted('africastalking_api_key', '');
        if ($apiKey === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->getContent(), $apiKey);

        return hash_equals($expected, $signature);
    }
}
