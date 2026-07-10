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
use App\Services\AfricasTalkingVoiceService;
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
    ) {}

    public function handle(Request $request): Response
    {
        if (! $this->verifySignature($request)) {
            return response('invalid signature', 401);
        }

        $event = $request->all();
        $sessionId = $event['sessionId'] ?? null;
        $direction = strtolower($event['direction'] ?? '');
        $status = $event['status'] ?? null;

        // ── Call-control phase ──────────────────────────────────────────
        // When AT has a LIVE call and needs to know what to do with it, it
        // POSTs the callback with isActive=1 and expects Voice XML back. This
        // is the missing piece that actually bridges audio: we answer with a
        // <Dial> to the agent's registered WebRTC browser client. The
        // isActive=0 event notifications (and legacy test payloads without
        // isActive) fall through to the status-tracking branch below.
        if ((string) ($event['isActive'] ?? '') === '1') {
            return $this->handleCallControl($event, $sessionId, $direction);
        }

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
            CallTerminated::dispatch($call->fresh(), 'remote_'.strtolower($status));
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
     * Respond to an Africa's Talking call-control request (isActive=1) with
     * Voice XML that bridges the live call to the right agent's browser
     * softphone.
     *
     *  - Inbound: ensure the conversation/CallLog chain exists (creating it
     *    on first contact), then <Dial> the assigned agent's WebRTC client.
     *  - Outbound: we already dialed the customer via REST; on answer, bridge
     *    them to the agent who placed the call.
     *
     * Returning anything other than valid Voice XML here is exactly the bug
     * that left callers hearing silence — AT needs a <Dial>/<Say>/<Reject>.
     */
    private function handleCallControl(array $event, ?string $sessionId, string $direction): Response
    {
        if ($direction === 'inbound') {
            $call = CallLog::where('provider_session_id', $sessionId)->first();
            if ($call === null) {
                // First contact for this inbound session: build the chain
                // (Contact/Conversation/assignment/CallLog) and broadcast
                // CallRinging so the agent's banner appears. We ignore the
                // Response it returns and re-read the persisted CallLog.
                $this->handleInboundFirstEvent($event);
                $call = CallLog::where('provider_session_id', $sessionId)->first();
            }

            $agentId = $call?->conversation?->assigned_to_user_id;
            if ($agentId === null) {
                // Nobody to route to — say so and let AT end the call.
                return $this->xmlResponse(
                    '<?xml version="1.0" encoding="UTF-8"?><Response>'
                    .'<Say>All our agents are currently busy. Please call again later.</Say>'
                    .'</Response>'
                );
            }

            return $this->xmlResponse($this->dialClientXml(
                AfricasTalkingVoiceService::clientNameForUser((int) $agentId),
                $event['callerNumber'] ?? null,
            ));
        }

        // Outbound bridge.
        $call = CallLog::where('provider_session_id', $sessionId)->first();
        if ($call === null || $call->placed_by_user_id === null) {
            Log::warning('AT outbound call-control with no placing agent', ['session_id' => $sessionId]);

            return $this->xmlResponse('<?xml version="1.0" encoding="UTF-8"?><Response><Reject/></Response>');
        }

        return $this->xmlResponse($this->dialClientXml(
            AfricasTalkingVoiceService::clientNameForUser((int) $call->placed_by_user_id),
            $call->to_phone,
        ));
    }

    /**
     * Build a <Dial> Voice XML action routing the call to a registered
     * browser client by name. callerId (when given) is what the agent's
     * softphone displays for the far party.
     */
    private function dialClientXml(string $clientName, ?string $callerId): string
    {
        $attrs = 'phoneNumbers="'.htmlspecialchars($clientName, ENT_QUOTES | ENT_XML1).'"';
        if ($callerId !== null && $callerId !== '') {
            $attrs .= ' callerId="'.htmlspecialchars($callerId, ENT_QUOTES | ENT_XML1).'"';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><Response><Dial '.$attrs.'/></Response>';
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Verify HMAC signature on incoming AT webhook.
     *
     * Verifies HMAC-SHA256 of the raw request body against the AT API key
     * (shared secret) in constant time, in EVERY environment. There is no
     * test-environment bypass: feature tests sign their payloads with the
     * same key (setEncrypted in setUp), so production and test paths are
     * identical and an accidental APP_ENV=local/staging deploy can never
     * leave the webhook unauthenticated.
     *
     * Exact header name per AT docs — verify at deploy time.
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Africastalking-Signature');
        if ($signature === null) {
            return false;
        }

        $apiKey = Setting::getEncrypted('africastalking_api_key', '');
        if ($apiKey === '') {
            // No key configured → fail closed (but only in prod-like envs a
            // key should exist; tests always set one).
            return false;
        }
        $expected = hash_hmac('sha256', $request->getContent(), $apiKey);

        return hash_equals($expected, $signature);
    }
}
