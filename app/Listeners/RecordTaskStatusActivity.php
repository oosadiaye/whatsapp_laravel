<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use App\Models\TaskActivity;

/**
 * Writes a history row for a column move.
 *
 * Deliberately NOT ShouldQueue. The card drawer renders the history list in the
 * same Livewire response that performed the action, so a queued write would show
 * the user their own change missing until the worker drained - the sort of
 * "it didn't save" that makes people click twice. The write is one INSERT, and
 * buying immediate-read-your-writes with it is the right trade.
 *
 * Failure is intentionally not swallowed and not retried either: the card has
 * already moved by the time this runs, and the exception surfaces in the log
 * rather than rolling the board back.
 */
class RecordTaskStatusActivity
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // recordOnce(), not create(): the event carries a key that the queued
        // mail listener correlates on, and that key is unique. See the method
        // for why a repeat write is a no-op rather than a failed request.
        TaskActivity::recordOnce($event->eventKey, [
            'task_id' => $task->id,
            'board_id' => (int) $task->board_id,
            'user_id' => $event->actorId,
            'type' => TaskActivity::TYPE_STATUS_CHANGED,
            'from_status' => $event->oldStatus,
            'to_status' => $event->newStatus,
        ]);
    }
}
