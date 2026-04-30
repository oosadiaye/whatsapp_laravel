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
        $callLog->appendRawEvent($eventName, $payload);

        switch ($eventName) {
            case 'connect':
                // First-event case is handled by processOne above.
                // If the row was already created, this is a duplicate connect — no-op.
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
                // Unknown events: log already appended above, no status change.
                Log::warning('Unknown call event from Meta', [
                    'event' => $eventName,
                    'wacid' => $callLog->meta_call_id,
                ]);
        }

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
        return Contact::firstOrCreate(
            ['user_id' => $instance->user_id, 'phone' => $phone],
            ['name' => $whatsappProfileName ?? $phone, 'is_active' => true],
        );
    }

    private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
    {
        return Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );
    }
}
