<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskBoardChanged;
use App\Models\Task;
use App\Models\TaskActivity;

/**
 * Writes a history row for everything that is not a column move.
 *
 * Synchronous for the same reason as RecordTaskStatusActivity: the drawer's
 * history panel renders in the response that made the change.
 *
 * Note the granularity. `TaskBoardChanged` carries a single `reason` for both
 * "a comment was added" and "a comment was deleted", and likewise for
 * assignment. The event is deliberately not widened to distinguish them — it is
 * the board's broadcast contract, and splitting it would mean every realtime
 * consumer grew a case for a distinction only the history cares about. The
 * undifferentiated entry is honest; an invented one would not be.
 */
class RecordBoardTaskActivity
{
    public function handle(TaskBoardChanged $event): void
    {
        if ($event->taskId === null) {
            return;
        }

        // The deleted case is the reason this reads withTrashed(): the row is
        // still there, it just has a deleted_at, and "who deleted this and when"
        // is exactly the kind of question the history exists to answer.
        $task = Task::withTrashed()->find($event->taskId);

        if ($task === null || $task->board_id === null) {
            return;
        }

        $type = match ($event->reason) {
            TaskBoardChanged::REASON_CREATED => TaskActivity::TYPE_CREATED,
            TaskBoardChanged::REASON_DELETED => TaskActivity::TYPE_DELETED,
            TaskBoardChanged::REASON_ASSIGNED => TaskActivity::TYPE_ASSIGNMENT_CHANGED,
            TaskBoardChanged::REASON_COMMENTED => TaskActivity::TYPE_COMMENTED,
            TaskBoardChanged::REASON_COMMENT_REMOVED => TaskActivity::TYPE_COMMENT_REMOVED,
            TaskBoardChanged::REASON_TRIAGE_UPDATED => TaskActivity::TYPE_TRIAGE_UPDATED,
            TaskBoardChanged::REASON_UPDATED => TaskActivity::TYPE_UPDATED,
            // An unrecognised reason is not recorded under a wrong heading. A
            // gap in the history is better than a lie in it - and the test that
            // checks every EMAILABLE_REASON is mapped means a reason added to
            // the event without a line here fails the suite, rather than
            // quietly producing an empty history.
            default => null,
        };

        if ($type === null) {
            return;
        }

        // The resulting assignee set is snapshotted so the entry can still
        // explain itself later: by the time someone reads "who changed this",
        // the assignees have moved on.
        $metadata = $event->reason === TaskBoardChanged::REASON_ASSIGNED
            ? ['assignee_ids' => $task->assignees()->pluck('users.id')->all()]
            : null;

        // recordOnce(), not create(): the event carries a key that the queued
        // mail listener correlates on, and that key is unique. See the method
        // for why a repeat write is a no-op rather than a failed request.
        TaskActivity::recordOnce($event->eventKey, [
            'task_id' => $task->id,
            'board_id' => (int) $task->board_id,
            'user_id' => $event->actorId,
            'type' => $type,
            // Only a creation has a meaningful "arrived in" column; for the
            // other reasons it would be two nulls no report ever reads.
            'to_status' => $event->reason === TaskBoardChanged::REASON_CREATED
                ? $task->status
                : null,
            'metadata' => $metadata,
        ]);
    }
}
