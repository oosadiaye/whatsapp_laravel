<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a person when a card is handed to them.
 *
 * One call per person newly attached, from the place that attaches them, rather
 * than inferred later from the activity log: "who was just added" is only known
 * at the moment of the toggle, and a diff afterwards cannot tell a re-assignment
 * from an assignment that was already there.
 *
 * Skipped for the person doing the assigning (assigning yourself needs no
 * mail), for deactivated accounts, for accounts with no address, and for anyone
 * who turned task email off.
 */
final class TaskAssignmentNotifier
{
    public function notify(Task $task, User $assignee, ?User $assigner): bool
    {
        if ($assigner !== null && (int) $assigner->id === (int) $assignee->id) {
            return false;
        }

        if (! $assignee->is_active || blank($assignee->email)) {
            return false;
        }

        if (! $assignee->wantsTaskNotificationsFor('assigned')) {
            return false;
        }

        Mail::to($assignee)->queue(new TaskAssignedMail($task, $task->board, $assigner?->name));

        return true;
    }
}
