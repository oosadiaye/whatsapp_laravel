<?php

declare(strict_types=1);

namespace App\Events\Calling;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallClaimed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CallLog $call)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        // assigned_to_user_id lives on Conversation, not CallLog (Task 4 finding).
        return new PrivateChannel('user.' . $this->call->conversation->assigned_to_user_id);
    }

    public function broadcastAs(): string
    {
        return 'call.claimed';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->call->id,
            'claimed_by_session_id' => $this->call->answered_by_session_id,
        ];
    }
}
