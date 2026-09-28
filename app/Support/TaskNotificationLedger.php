<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Models\TaskActivity;
use App\Models\TaskActivityNotification;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Makes one activity notify each person at most once.
 *
 * The problem this solves is not theoretical. Both notification listeners are
 * queued and both fan out in a loop, so a throw on the third of five recipients
 * causes the whole listener job to be retried - and without a ledger the first
 * two get a second copy. A partial failure inside a loop is the most likely way
 * a duplicate email actually happens here.
 *
 * ## What it does not do
 *
 * It is not exactly-once delivery, and claiming otherwise would be a claim the
 * code cannot back. The remaining window: the mailable is handed to the queue,
 * then the process dies before `markSent()` commits. The retry sees no ledger
 * row and sends again. That window is small and needs a crash to hit, but it
 * is real.
 *
 * Getting it in that order is a deliberate choice. Claiming the row *first* and
 * queueing second would close that window too, at the cost of a new one: a
 * failure while queueing would leave a recorded "sent" that never sent, and the
 * retry would skip it forever. A lost notification is worse than a duplicate
 * one - the person does not know to go looking - so the record is written last.
 */
class TaskNotificationLedger
{
    /**
     * The activity row for this event, or null if the log row is missing.
     *
     * Null is possible: the recorders are synchronous and the notification
     * listeners are queued, and a recorder that threw (or a test that dispatched
     * the event directly) leaves nothing to correlate against. Callers treat null
     * as "no dedupe available" and still send - bookkeeping must never be able to
     * silence a product feature.
     */
    public function activityIdFor(TaskStatusChanged|TaskBoardChanged $event): ?int
    {
        if ($event->eventKey === null) {
            return null;
        }

        $id = TaskActivity::where('event_key', $event->eventKey)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function alreadySent(?int $activityId, int $userId): bool
    {
        if ($activityId === null) {
            return false;
        }

        return TaskActivityNotification::query()
            ->where('activity_id', $activityId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Record a completed hand-off to the queue.
     *
     * Returns false if it was already recorded, which is the unique index doing
     * the work rather than a read-then-write that could interleave.
     */
    public function markSent(?int $activityId, int $userId): bool
    {
        if ($activityId === null) {
            return false;
        }

        try {
            TaskActivityNotification::create([
                'activity_id' => $activityId,
                'user_id' => $userId,
                'sent_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
