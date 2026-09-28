<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskBoardChanged;
use App\Mail\TaskActivityMail;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskNotificationLedger;
use App\Support\TaskNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the people on a card about a comment, an assignment change, a
 * description edit, or a deletion.
 *
 * Only reaches users who opted into "all" activity; anyone on the default
 * "transitions" level is filtered out inside the resolver, so this listener
 * firing on every board event is not itself a source of inbox noise.
 */
class SendTaskActivityEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly TaskNotificationRecipients $recipients = new TaskNotificationRecipients,
        private readonly TaskNotificationLedger $ledger = new TaskNotificationLedger,
    ) {}

    public function handle(TaskBoardChanged $event): void
    {
        // A new card is not an announcement: the person who raised it knows,
        // and mailing the board on every creation is how people learn to
        // ignore notifications altogether.
        if (! in_array($event->reason, TaskBoardChanged::EMAILABLE_REASONS, true)) {
            return;
        }

        if ($event->taskId === null) {
            return;
        }

        // withTrashed() because the deleted case is one of the reasons this
        // listener exists - the row is still there, it just has a deleted_at.
        $task = Task::withTrashed()->with(['board.owner', 'assignees', 'watchers'])->find($event->taskId);

        if ($task === null || $task->board === null) {
            return;
        }

        // A deleted card has no board row left to hang the channel off if the
        // board itself went with it; the guard above covers that.
        $actorName = $event->actorId !== null
            ? User::find($event->actorId)?->name
            : null;

        $users = $this->recipients->forTask($task, $event->actorId, 'activity');

        $activityId = $this->ledger->activityIdFor($event);

        foreach ($users as $user) {
            $userId = (int) $user->id;

            // A throw partway round this loop retries the whole listener job, so
            // without the ledger the recipients already handled get a second
            // copy. See TaskNotificationLedger.
            if ($this->ledger->alreadySent($activityId, $userId)) {
                continue;
            }

            Mail::to($user)->queue(new TaskActivityMail($task, $event->reason, $task->board, $actorName));

            $this->ledger->markSent($activityId, $userId);
        }
    }
}
