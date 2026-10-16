<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallTerminated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call, public string $reason) {}

    public function broadcastOn(): PrivateChannel
    {
        // Fall back to the placing agent for unassigned (outbound) conversations,
        // and to 0 so the channel is never "user." (null).
        $userId = (int) ($this->call->conversation?->assigned_to_user_id
            ?? $this->call->placed_by_user_id
            ?? 0);

        return new PrivateChannel('user.'.$userId);
    }

    public function broadcastAs(): string
    {
        return 'call.terminated';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'reason' => $this->reason,
            // Why the call died, when the provider told us (e.g. 'AT bridge:
            // NO_USER_RESPONSE'). Without it the agent's banner just vanished and they
            // were left guessing; the browser shows it.
            'failure_reason' => $this->call->failure_reason,
        ];
    }
}
