<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A card on a board changed in a way that is not a column move.
 *
 * Why this is separate from TaskStatusChanged: a move has to do two jobs (mail
 * the owner, push to the board), while the changes covered here only need the
 * push. Folding them together would either email on every new comment or push
 * twice on every move. Moves are therefore deliberately NOT reported here —
 * they are already covered by TaskStatusChanged, and emitting both would make
 * each subscriber re-render twice for one drag.
 *
 * The push applies to everyone on the board; the email is narrower, because
 * only people on the card (owner, assignees, watchers) have any stake in it,
 * and only if they asked for it. See TaskNotificationRecipients.
 */
class TaskBoardChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const REASON_CREATED = 'created';

    public const REASON_DELETED = 'deleted';

    public const REASON_ASSIGNED = 'assigned';

    public const REASON_COMMENTED = 'commented';

    /**
     * Removing a comment is its own reason, not a second REASON_COMMENTED.
     *
     * It used to be a second REASON_COMMENTED, which meant retracting a comment
     * mailed every subscriber the subject "Card title: new comment" and the line
     * "left a comment". That is worse than a missing notification: the usual
     * reason to delete a comment is to take something back, and the system was
     * announcing it to everyone on the card.
     */
    public const REASON_COMMENT_REMOVED = 'comment_removed';

    /** Description edits only, so "updated the description" is never a lie. */
    public const REASON_UPDATED = 'updated';

    /**
     * Due date / priority / estimate. Kept apart from REASON_UPDATED so a change
     * of deadline is not reported to the card's subscribers as a text edit.
     */
    public const REASON_TRIAGE_UPDATED = 'triage_updated';

    /**
     * Reasons worth an email to card-level subscribers. A new card is not on
     * this list on purpose: the person who raised it already knows, and "a card
     * now exists" is exactly the sort of announcement that teaches people to
     * mute notifications entirely.
     *
     * Any reason added above must be added here or it will not be emailed —
     * that is the intended failure (a silent reason is a bug in this list, not a
     * policy), and TaskActivityTest asserts every emailable reason is recorded
     * in the log, so a gap shows up as a failing test rather than a lost email.
     *
     * @var list<string>
     */
    public const EMAILABLE_REASONS = [
        self::REASON_DELETED,
        self::REASON_ASSIGNED,
        self::REASON_COMMENTED,
        self::REASON_COMMENT_REMOVED,
        self::REASON_UPDATED,
        self::REASON_TRIAGE_UPDATED,
    ];

    /**
     * Correlation key, minted once here so it is on the event from birth.
     *
     * See TaskStatusChanged::$eventKey and the 174000 migration: both the
     * synchronous recorder and the queued mail listener need to identify the
     * same log row, and only a value carried on the event can do that without
     * depending on listener order or guessing.
     */
    public ?string $eventKey = null;

    public function __construct(
        public int $boardId,
        public string $reason,
        public ?int $taskId = null,
        // Whoever made the change, so the notification resolver never emails
        // someone about their own action.
        public ?int $actorId = null,
    ) {
        $this->eventKey = (string) Str::uuid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('boards.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'task.board.changed';
    }

    /**
     * Only what the client needs to decide whether to re-render. The reason is
     * carried so the UI can skip the refresh when the change is one it already
     * applied locally, and so future consumers can special-case it without
     * inferring intent from a missing field.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'boardId' => $this->boardId,
            'taskId' => $this->taskId,
            'reason' => $this->reason,
        ];
    }
}
