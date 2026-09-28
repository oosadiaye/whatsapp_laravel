<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TaskStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Runtime board-column management.
 *
 * Gated on tasks.status.manage (manager+), so an administrator can reshape the
 * workflow — add a stage, rename it, reorder columns — without a deploy and
 * without letting agents redefine the process.
 *
 * Two invariants are enforced here rather than in the DB, because tasks.status
 * is a denormalized string column:
 *   1. exactly one default status;
 *   2. a status that is the default, or that still has cards in it, cannot be
 *      deleted or have its slug changed (the slug IS tasks.status).
 *
 * Plus one about the optional work-in-progress ceiling: a completed column
 * cannot carry one, and flagging a column completed retires it.
 *
 * The ceiling is deliberately advisory - nothing here, or anywhere else, turns
 * an over-limit column into a refused move. The reasoning, since it is the kind
 * of thing that looks like an oversight and gets "fixed" later:
 *
 *   - A hard limit is unrecoverable when set below current occupancy. The
 *     column is already over, nobody may add to it, and the only escape is an
 *     admin noticing and editing the number. The team's answer to a real
 *     constraint would be to route around the board, not through it.
 *   - It punishes the person who reports the problem. A limit set at 3 with
 *     four cards already in Review blocks the fifth card from being *filed*,
 *     which is exactly the card that documents that Review is overflowing.
 *   - A WIP limit's job is to make a pile-up visible at the moment it grows, so
 *     that someone finishes work instead of starting more. That is a prompt,
 *     not a gate - the same reasoning Trello and Jira ship with, warn-only by
 *     default.
 */
class TaskStatusController extends Controller
{
    public function index(): View
    {
        $statuses = TaskStatus::query()
            ->ordered()
            ->withCount('tasks')
            ->get();

        return view('task-statuses.index', ['statuses' => $statuses]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']));
        $data['position'] = ($request->integer('position'))
            ?: (int) (TaskStatus::max('position') + 1);

        if ($this->rejectsLimitOnFinishedColumn($data)) {
            return back()->with('error', $this->finishedColumnLimitMessage());
        }

        $data = $this->retireLimitWhenFinished($data);

        DB::transaction(function () use ($data): void {
            if ($data['is_default'] ?? false) {
                $this->clearDefault();
            }

            TaskStatus::create($data);
        });

        return redirect()
            ->route('task-statuses.index')
            ->with('success', 'Status added.');
    }

    public function update(Request $request, TaskStatus $taskStatus): RedirectResponse
    {
        $data = $this->validated($request, $taskStatus);

        // The slug is persisted on every card in this column, so it is only
        // mutable while the column is empty — otherwise a rename would
        // silently strand existing cards in a column that no longer exists.
        if ($data['slug'] !== $taskStatus->slug && $taskStatus->tasks()->withTrashed()->exists()) {
            return back()->with(
                'error',
                'Move the cards out of this column before changing its key.'
            );
        }

        if ($this->rejectsLimitOnFinishedColumn($data)) {
            return back()->with('error', $this->finishedColumnLimitMessage());
        }

        $data['slug'] = $this->uniqueSlug($data['slug'], $taskStatus->id);
        $data = $this->retireLimitWhenFinished($data);

        DB::transaction(function () use ($data, $taskStatus): void {
            if ($data['is_default'] ?? false) {
                $this->clearDefault();
            }

            $taskStatus->update($data);
        });

        return redirect()
            ->route('task-statuses.index')
            ->with('success', 'Status updated.');
    }

    public function destroy(TaskStatus $taskStatus): RedirectResponse
    {
        if ($taskStatus->is_default) {
            return back()->with(
                'error',
                'This is the default column. Promote another column to default first.'
            );
        }

        // withTrashed(): a soft-deleted card still carries this status slug and can
        // be restored, so deleting/renaming the column while any card — live or
        // trashed — references it would orphan that card's status.
        if ($taskStatus->tasks()->withTrashed()->exists()) {
            return back()->with(
                'error',
                'Move the cards out of this column before deleting it.'
            );
        }

        $taskStatus->delete();

        return redirect()
            ->route('task-statuses.index')
            ->with('success', 'Status deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?TaskStatus $status = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('task_statuses', 'slug')->ignore($status?->id),
            ],
            'color' => ['required', Rule::in(TaskStatus::COLORS)],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_completed' => ['nullable', 'boolean'],
            // Present-but-empty must clear the ceiling rather than fail on
            // "required", so nullable - and 0 is rejected because "nothing may
            // ever enter this column" is never the intent, it is a typo.
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
    }

    /**
     * A finished column cannot carry a work-in-progress ceiling.
     *
     * Checked rather than silently dropped, because the submit that trips this
     * is a real form submission with a number typed into it, and quietly
     * discarding typed input is how a setting goes in believing it took.
     *
     * @param  array<string, mixed>  $data
     */
    private function rejectsLimitOnFinishedColumn(array $data): bool
    {
        return ($data['wip_limit'] ?? null) !== null && (bool) ($data['is_completed'] ?? false);
    }

    private function finishedColumnLimitMessage(): string
    {
        return 'A finished column cannot have a work-in-progress limit — there is no such thing as too much finished work.';
    }

    /**
     * Flagging a column finished retires its ceiling.
     *
     * Cleared rather than left dormant, so that re-opening the column later
     * does not silently resurrect a limit somebody set months ago and forgot.
     * The ceiling is advisory anyway, so nothing is lost — effectiveLimit()
     * would have ignored it in the meantime.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function retireLimitWhenFinished(array $data): array
    {
        if ($data['is_completed'] ?? false) {
            $data['wip_limit'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function clearDefault(): void
    {
        TaskStatus::query()->where('is_default', true)->update(['is_default' => false]);
    }

    /**
     * Guarantee a usable unique key, falling back to a numeric suffix so a
     * duplicate name still saves instead of erroring opaquely.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug) ?: 'status';
        $candidate = $base;
        $suffix = 1;

        while (
            TaskStatus::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }
}
