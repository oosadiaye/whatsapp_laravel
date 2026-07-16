<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when InboundCallProcessor sees a new ringing call. Routes
 * to the assigned agent's user-scoped private channel so only that
 * agent's open browser tabs receive the SDP offer + Accept/Decline UI.
 *
 * Phase 17 — replaces the Phase 14.1 polled-banner discovery model
 * with real-time push (~100ms latency vs. up-to-3s).
 */
class CallRinging implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->targetUserId());
    }

    /**
     * The agent this call belongs to: the conversation's assignee, else the
     * agent who placed it (outbound calls are often on an unassigned
     * conversation). Falls back to 0 so the channel is never "user." (null).
     */
    private function targetUserId(): int
    {
        return (int) ($this->call->conversation?->assigned_to_user_id
            ?? $this->call->placed_by_user_id
            ?? 0);
    }

    public function broadcastAs(): string
    {
        return 'call.ringing';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'meta_call_id' => $this->call->meta_call_id,
            'contact_name' => $this->call->contact->display_name ?? null,
            'phone' => $this->call->from_phone,
            'sdp_offer' => $this->call->sdp_offer,
        ];
    }
}
