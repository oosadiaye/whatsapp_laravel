<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses Meta's `calls` webhook field into local call_log rows.
 *
 * Mirrors {@see InboundMessageProcessor}'s structure. Each call event from
 * Meta updates the same call_log row (looked up by meta_call_id), so
 * webhook retries are idempotent.
 *
 * Event lifecycle:
 *   connect    → status=ringing, started_at set
 *   accept     → status=connected, connected_at set
 *   disconnect → status=ended, ended_at set, duration_seconds calculated
 *   missed     → status=missed, ended_at set (no connected_at)
 *   declined   → status=declined, ended_at set
 *   fail/error → status=failed, ended_at set
 *
 * Unknown events are appended to raw_event_log without changing status,
 * so future Meta event types don't break anything — they just log silently
 * for later inspection.
 */
class InboundCallProcessor
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
        private readonly RoundRobinAssigner $roundRobinAssigner,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $calls  from value.calls[]
     * @param  array<int, array<string, mixed>>  $contactsBlock  from value.contacts[]
     */
    public function processCalls(
        WhatsAppInstance $instance,
        array $calls,
        array $contactsBlock = [],
    ): void {
        $nameByPhone = [];
        foreach ($contactsBlock as $c) {
            $waId = (string) ($c['wa_id'] ?? '');
            $name = (string) ($c['profile']['name'] ?? '');
            if ($waId !== '' && $name !== '') {
                $nameByPhone[$waId] = $name;
            }
        }

        foreach ($calls as $event) {
            try {
                $this->processOne($instance, $event, $nameByPhone);
            } catch (Throwable $e) {
                Log::error('Inbound call event processing failed', [
                    'instance_id' => $instance->id,
                    'wacid' => $event['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $nameByPhone
     */
    private function processOne(WhatsAppInstance $instance, array $event, array $nameByPhone): void
    {
        $callId = (string) ($event['id'] ?? '');
        if ($callId === '') {
            return;
        }

        $eventName = strtolower((string) ($event['event'] ?? ''));
        $fromPhone = (string) ($event['from'] ?? '');
        $toPhone = (string) ($event['to'] ?? '');

        $callLog = CallLog::where('meta_call_id', $callId)->first();

        if ($callLog === null) {
            // First time seeing this call. Find/create contact + conversation.
            if ($fromPhone === '') {
                return;  // Can't infer contact without a phone
            }

            $contact = $this->findOrCreateContact($instance, $fromPhone, $nameByPhone[$fromPhone] ?? null);
            $conversation = $this->findOrCreateConversation($instance, $contact);

            $callLog = CallLog::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'whatsapp_instance_id' => $instance->id,
                'direction' => CallLog::DIRECTION_INBOUND,
                'meta_call_id' => $callId,
                'status' => CallLog::STATUS_RINGING,
                'from_phone' => $fromPhone,
                'to_phone' => $toPhone,
                'started_at' => $this->eventTime($event),
            ]);

            // Phase 17: persist SDP offer + tell Meta we're engaging + push to agent's browser.
            //
            // Normalise line endings to CRLF before storing. RFC 4566 mandates
            // CRLF between SDP lines; Chrome's RTCPeerConnection parser
            // enforces this strictly and rejects bare-LF SDP with:
            //   "Failed to parse SessionDescription. <last-seen line>
            //    Invalid SDP line."
            // Meta's webhook delivers the SDP via JSON, and depending on
            // server/client encoding, the original CRLFs sometimes arrive
            // as bare LFs. Normalise here so EVERY consumer (browser,
            // CallRinging broadcast, debug dumps) gets canonical CRLF.
            // Idempotent: collapse \r\n -> \n, then expand \n -> \r\n.
            $sdpOffer = $event['session']['sdp'] ?? null;
            if ($sdpOffer !== null) {
                $sdpOffer = str_replace("\r\n", "\n", $sdpOffer);
                $sdpOffer = str_replace("\n", "\r\n", $sdpOffer);
                $callLog->update(['sdp_offer' => $sdpOffer]);
            }

            // Pre-accept is fire-and-forget — failure does NOT abort the call.
            // preAcceptCall handles its own 4xx logging.
            try {
                $this->cloudApi->preAcceptCall($instance, $callLog->meta_call_id);
            } catch (Throwable $e) {
                Log::warning('preAcceptCall threw unexpectedly; continuing', ['error' => $e->getMessage()]);
            }

            // Push the SDP offer + call metadata to the assigned agent's browser
            // over Reverb. Only fire if there's an assignee — unassigned calls
            // (round-robin returned null) have no target channel.
            if ($conversation->assigned_to_user_id !== null) {
                \App\Events\Calling\CallRinging::dispatch($callLog);
            }
        }

        // Apply the state transition for the new event
        $this->applyEvent($callLog, $eventName, $event);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(CallLog $callLog, string $eventName, array $payload): void
    {
        $eventTime = $this->eventTime($payload);

        switch ($eventName) {
            case 'connect':
                // First-event creation is handled by processOne above.
                // A duplicate connect on an already-ringing log is a webhook
                // retry — drop it entirely to keep raw_event_log bounded.
                if ($callLog->status === CallLog::STATUS_RINGING) {
                    return;
                }
                break;

            case 'accept':
            case 'connect_complete':
                $callLog->status = CallLog::STATUS_CONNECTED;
                $callLog->connected_at = $eventTime;
                break;

            case 'disconnect':
                $callLog->status = CallLog::STATUS_ENDED;
                $callLog->ended_at = $eventTime;
                if ($callLog->connected_at !== null) {
                    $callLog->duration_seconds = (int) $callLog->connected_at->diffInSeconds($eventTime);
                }
                break;

            case 'missed':
            case 'no_answer':
                $callLog->status = CallLog::STATUS_MISSED;
                $callLog->ended_at = $eventTime;
                break;

            case 'reject':
            case 'declined':
                $callLog->status = CallLog::STATUS_DECLINED;
                $callLog->ended_at = $eventTime;
                $callLog->failure_reason = (string) ($payload['reason'] ?? 'Declined by recipient');
                break;

            case 'fail':
            case 'error':
                $callLog->status = CallLog::STATUS_FAILED;
                $callLog->ended_at = $eventTime;
                $callLog->failure_reason = (string) ($payload['error']['message'] ?? 'Call failed');
                break;

            default:
                Log::warning('Unknown call event from Meta', [
                    'event' => $eventName,
                    'wacid' => $callLog->meta_call_id,
                ]);
                // Fall through: append unknown events to raw log for debugging,
                // but no status change.
        }

        // Only reached when the event causes a genuine state transition or is an
        // unknown event worth recording. Duplicate connects returned early above.
        $callLog->appendRawEvent($eventName, $payload);
        $callLog->save();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventTime(array $event): Carbon
    {
        return isset($event['timestamp'])
            ? Carbon::createFromTimestamp((int) $event['timestamp'])
            : Carbon::now();
    }

    private function findOrCreateContact(
        WhatsAppInstance $instance,
        string $phone,
        ?string $whatsappProfileName,
    ): Contact {
        // IncludingTrashed: revive a soft-deleted contact rather than crash on
        // the unversioned unique index (see Contact::firstOrNewIncludingTrashed).
        return Contact::firstOrCreateIncludingTrashed(
            ['user_id' => $instance->user_id, 'phone' => $phone],
            ['name' => $whatsappProfileName ?? $phone, 'is_active' => true],
        );
    }

    private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );

        // Auto-assign to next available agent IF currently unassigned.
        // Sticky-to-existing-assignment is implicit: already-assigned conversations
        // skip this branch entirely. Mirrors InboundMessageProcessor — the same
        // RoundRobinAssigner serves both processors so call+message rotations
        // share the same fairness pointer (last_assigned_at on User).
        if ($conversation->assigned_to_user_id === null) {
            $agent = $this->roundRobinAssigner->next();
            if ($agent !== null) {
                $conversation->update(['assigned_to_user_id' => $agent->id]);
            }
        }

        return $conversation;
    }
}
