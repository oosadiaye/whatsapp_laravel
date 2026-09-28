<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Mail\TaskStatusChangedMail;
use App\Support\TaskNotificationLedger;
use App\Support\TaskNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the people on a card when it changes column.
 *
 * Queued so a slow SMTP handshake never blocks the Livewire drag-and-drop
 * request that triggered the move. The audience is resolved here rather than at
 * dispatch time because the listener may run minutes later, by which point an
 * assignee can have been added or a user deactivated.
 */
class SendTaskStatusEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly TaskNotificationRecipients $recipients = new TaskNotificationRecipients,
        private readonly TaskNotificationLedger $ledger = new TaskNotificationLedger,
    ) {}

    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // loadMissing() inside the resolver gives the mail a board and statuses
        // even when this listener runs against an event that has been through
        // the queue and lost its relations.
        $task->loadMissing(['board.owner', 'assignees', 'watchers']);

        if ($task->board === null) {
            return;
        }

        $users = $this->recipients->forTask($task, $event->actorId, 'transition');

        // Null when the log row is missing, in which case there is nothing to
        // dedupe against. The mail still goes out: bookkeeping must never be
        // able to silence the feature.
        $activityId = $this->ledger->activityIdFor($event);

        foreach ($users as $user) {
            $userId = (int) $user->id;

            // The whole point of the listener being queued and fanning out: a
            // throw on the third of five recipients retries the whole job, and
            // without this the first two get a second copy.
            if ($this->ledger->alreadySent($activityId, $userId)) {
                continue;
            }

            // queue() rather than send(): the listener is already queued, so
            // this second hop keeps one bad address from failing the whole
            // batch and leaves each recipient as an independently retryable job.
            Mail::to($user)->queue(new TaskStatusChangedMail($task, $event->oldStatus, $event->newStatus, $task->board));

            // Recorded after the hand-off, not before. Claiming first would
            // turn a failure here into a notification that is never sent but
            // permanently marked as sent - and a lost email is worse than a
            // duplicate, because nobody knows to go looking for it.
            $this->ledger->markSent($activityId, $userId);
        }
    }
}
