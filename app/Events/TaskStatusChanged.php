<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A card changed column.
 *
 * Two consumers, deliberately:
 *   - SendTaskStatusEmail (queued listener) mails the board owner;
 *   - ShouldBroadcast pushes to the board's private channel so every other
 *     agent looking at the same board updates without a refresh.
 *
 * Broadcasting is best-effort: a broker hiccup must never fail the Livewire
 * request that moved the card, and the email path must not depend on it.
 */
class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Correlation key, minted once here so it is on the event from birth.
     *
     * The activity recorder and the queued mail listener both need to agree on
     * which log row belongs to this event. Neither reading it off another
     * listener (order-dependent) nor guessing "the latest matching row" (racy
     * when two people touch one card) works. See the 174000 migration.
     */
    public ?string $eventKey = null;

    public function __construct(
        public Task $task,
        public string $oldStatus,
        public string $newStatus,
        // Whoever made the move. Null for a change made outside a request (a
        // console command, a queued job). Carried so the notification
        // resolver can skip emailing people about their own action.
        public ?int $actorId = null,
    ) {
        $this->eventKey = (string) Str::uuid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('boards.'.$this->task->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }

    /**
     * Only the identifiers the UI needs. Keeps the payload small and avoids
     * shipping a full model (and its relations) to every subscriber.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'taskId' => $this->task->id,
            'boardId' => $this->task->board_id,
            'oldStatus' => $this->oldStatus,
            'newStatus' => $this->newStatus,
        ];
    }
}
