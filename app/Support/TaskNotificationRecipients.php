<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides who gets emailed about a change to a card.
 *
 * The audience is the union of three overlapping groups - the board owner, the
 * card's assignees, and anyone watching it - which means the naive version of
 * this sends the same person up to three copies of one email. Collecting them
 * into a single keyed set is what makes "one email per person" true by
 * construction rather than by remembering to check.
 *
 * Two exclusions matter as much as the inclusions:
 *
 *  - the actor is dropped, because nobody needs to be emailed about the thing
 *    they just did (and the board owner is very often also the person dragging);
 *  - deactivated accounts are dropped, because mail to a departed employee's
 *    inbox is both useless and a deliverability risk for the sending domain.
 */
final class TaskNotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function forTask(Task $task, ?int $actorId, string $kind): Collection
    {
        $board = $task->board;

        if ($board === null) {
            return collect();
        }

        return $this->collectCandidates($task, $board->user_id)
            // Keyed by id, so the first group to claim a person wins and the
            // later groups cannot re-add them.
            ->keyBy('id')
            ->reject(fn (User $user) => (int) $user->id === (int) $actorId)
            ->reject(fn (User $user) => ! $user->is_active)
            ->filter(fn (User $user) => filled($user->email))
            ->filter(fn (User $user) => $user->wantsTaskNotificationsFor($kind))
            ->values();
    }

    /**
     * Owner first, then assignees, then watchers - a person in several groups
     * is loaded once and the ordering only affects which instance survives the
     * keyBy().
     *
     * @return Collection<int, User>
     */
    private function collectCandidates(Task $task, int $ownerId): Collection
    {
        $task->loadMissing(['board.owner', 'assignees', 'watchers']);

        $users = collect();

        $owner = $task->board?->owner;
        if ($owner !== null) {
            $users->push($owner);
        }

        foreach ($task->assignees as $assignee) {
            $users->push($assignee);
        }

        foreach ($task->watchers as $watcher) {
            $users->push($watcher);
        }

        // The owner is included above via the relation; if it failed to load
        // (owner deleted, board orphaned) fall back to a direct lookup so a
        // missing relation cannot silently drop the primary recipient.
        if ($owner === null) {
            $fallback = User::find($ownerId);
            if ($fallback !== null) {
                $users->push($fallback);
            }
        }

        return $users;
    }
}
