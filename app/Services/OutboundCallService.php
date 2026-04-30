<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;

/**
 * Wraps {@see WhatsAppCloudApiService} for outbound call orchestration:
 *  - call Meta's API to initiate the dial
 *  - create a corresponding call_log row with the returned Meta call ID
 *
 * Subsequent webhook events from Meta update the same call_log via
 * {@see InboundCallProcessor}, identified by meta_call_id.
 */
class OutboundCallService
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {
    }

    /**
     * @throws WhatsAppApiException  if Meta rejects the call request
     */
    public function initiate(Conversation $conversation, User $placedBy): CallLog
    {
        $instance = $conversation->whatsappInstance;
        $contact = $conversation->contact;

        $metaCallId = $this->cloudApi->initiateCall($instance, $contact->phone);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'meta_call_id' => $metaCallId,
            'status' => CallLog::STATUS_INITIATED,
            'from_phone' => (string) ($instance->business_phone_number ?? $instance->phone_number_id),
            'to_phone' => $contact->phone,
            'started_at' => now(),
            'placed_by_user_id' => $placedBy->id,
            'raw_event_log' => [],
        ]);
    }

    /**
     * Hang up an in-flight call. Updates the call_log optimistically to
     * status=ended; if Meta's API rejects, the call is still on at Meta's
     * side — caller can retry or wait for the natural disconnect webhook.
     *
     * @throws WhatsAppApiException
     */
    public function end(CallLog $callLog): void
    {
        if ($callLog->meta_call_id === null) {
            throw new WhatsAppApiException(
                'Cannot end call without meta_call_id (was the call ever initiated successfully?)'
            );
        }

        $this->cloudApi->endCall($callLog->whatsappInstance, $callLog->meta_call_id);

        $callLog->update([
            'status' => CallLog::STATUS_ENDED,
            'ended_at' => now(),
        ]);
    }
}
