<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\Board;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadReply;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Files a card for the person who owns a lead when that lead says yes, so the follow-up call is on
 * the board and not only in a toast that can be missed.
 *
 * The card goes on a "Leads" board that belongs to the owner (made on first use) and is assigned to
 * them. It is best effort by design: a missing owner, an archived board or a write that keeps
 * colliding returns null and never gets in the way of handling the reply itself.
 */
final class LeadFollowUpTask
{
    public const BOARD_SLUG = 'leads';

    private const DUE_AFTER_HOURS = 24;

    private const POSITION_ATTEMPTS = 3;

    /** One card per reply, however many times the reply job runs. */
    public function createFor(Lead $lead, LeadReply $reply, string $summary): ?Task
    {
        $lock = Cache::lock("lead-task:{$reply->id}", 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            if ($this->alreadyFiled($lead, $reply)) {
                return null;
            }

            $owner = $this->owner($lead);
            $board = $owner === null ? null : $this->board($owner);
            if ($owner === null || $board === null) {
                return null;
            }

            $task = $this->file($lead, $board, $owner, $summary);
            if ($task === null) {
                return null;
            }

            LeadEvent::record($lead, 'task_created', ['reply_id' => $reply->id, 'task_id' => $task->id], 'system');

            return $task;
        } finally {
            $lock->release();
        }
    }

    private function alreadyFiled(Lead $lead, LeadReply $reply): bool
    {
        return LeadEvent::query()
            ->where('lead_id', $lead->id)
            ->where('type', 'task_created')
            ->where('payload->reply_id', $reply->id)
            ->exists();
    }

    private function owner(Lead $lead): ?User
    {
        $ownerId = $lead->owner_user_id ?? $lead->profile?->created_by;
        $owner = $ownerId === null ? null : User::find($ownerId);

        return $owner !== null && $owner->is_active ? $owner : null;
    }

    /** An archived board is a decision to stop using it, so it is left alone rather than revived. */
    private function board(User $owner): ?Board
    {
        try {
            $board = Board::firstOrCreate(
                ['user_id' => $owner->id, 'slug' => self::BOARD_SLUG],
                ['name' => 'Leads', 'description' => 'Follow-ups for leads who replied with interest.'],
            );
        } catch (UniqueConstraintViolationException) {
            $board = Board::where('user_id', $owner->id)->where('slug', self::BOARD_SLUG)->first();
        }

        return $board !== null && $board->archived_at === null ? $board : null;
    }

    private function file(Lead $lead, Board $board, User $owner, string $summary): ?Task
    {
        $status = TaskStatus::defaultSlug();

        for ($attempt = 1; $attempt <= self::POSITION_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($lead, $board, $owner, $summary, $status): Task {
                    // withTrashed(): the unique index counts deleted cards, so the next slot must too.
                    $last = Task::withTrashed()->where('board_id', $board->id)->where('status', $status)->max('position');

                    $task = Task::create([
                        'user_id' => $owner->id,
                        'board_id' => $board->id,
                        'title' => $this->title($lead),
                        'description' => $this->description($lead, $summary),
                        'status' => $status,
                        'position' => $last === null ? 0 : (int) $last + 1,
                        'priority' => Task::PRIORITY_HIGH,
                        'due_at' => now()->addHours(self::DUE_AFTER_HOURS),
                    ]);
                    $task->assignees()->attach($owner->id);

                    return $task;
                });
            } catch (UniqueConstraintViolationException) {
                // Someone filed into the same column at the same moment; read the slot again.
                continue;
            }
        }

        return null;
    }

    private function title(Lead $lead): string
    {
        $who = trim((string) $lead->name) !== '' ? (string) $lead->name : (string) $lead->email;
        $company = trim((string) $lead->company);

        return mb_substr('Follow up: '.$who.($company !== '' ? " ({$company})" : '').' is interested', 0, 255);
    }

    private function description(Lead $lead, string $summary): string
    {
        return trim($summary)."\n\n".route('leads.show', $lead);
    }
}
