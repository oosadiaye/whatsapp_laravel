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

    public function __construct(public CallLog $call, public string $reason)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.' . $this->call->conversation->assigned_to_user_id);
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
        ];
    }
}
