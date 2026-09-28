<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class TaskBoard extends Component
{
    /** How many history rows the drawer shows. */
    private const HISTORY_LIMIT = 20;

    /**
     * Upper bound for a client-supplied move slot. Far above any real column, but
     * safely below the signed-INT position column ceiling so a crafted huge slot
     * can't overflow on reservePosition's increment. Slots past a column end are
     * intentionally allowed (gaps are harmless); this only stops absurd values.
     */
    private const MAX_POSITION_SLOT = 1000000;

    public ?int $boardId = null;

    public string $title = '';

    public string $description = '';

    public string $newTaskStatus = '';

    public string $search = '';

    public ?int $editingTaskId = null;

    /** The card whose detail drawer is open, or null when the board is closed. */
    public ?int $openTaskId = null;

    public string $commentBody = '';

    public string $detailDescription = '';

    /** Drawer-bound triage fields, mirrored from the open card on openTask(). */
    public string $detailDueAt = '';

    public int $detailPriority = Task::PRIORITY_NORMAL;

    public string $detailEstimate = '';

    /** "Only my cards" filter - the reason the assignee pivot exists. */
    public bool $onlyMine = false;

    /** "Past due" filter, so overdue styling is actually actionable. */
    public bool $onlyOverdue = false;

    /** One of Task::SORT_MODES. Only 'manual' reads the stored position. */
    public string $sortMode = Task::SORT_MANUAL;

    /**
     * Advisory WIP notice, never a block.
     *
     * Set on the move or create that pushes a column past its ceiling, cleared
     * by the next move that does not, and dismissible. Null rather than a
     * hidden bool because the message is the thing worth saying out loud —
     * "3/4" turning red in a column header is easy to miss when you are
     * dragging a card, and the drag is the moment the ceiling can still change
     * what someone does next.
     */
    public ?string $wipNotice = null;

    /**
     * Columns memoised for this request.
     *
     * @var array<string, array{name: string, color: string, completed: bool, limit: int|null}>|null
     */
    private ?array $statusColumns = null;

    public function mount(?Board $board = null): void
    {
        // `live()` on the fallback: an archived board must never become the one
        // someone is dropped onto just because it has the lowest id.
        $this->boardId = $board?->id ?? Board::query()->live()->orderBy('id')->value('id');
        $this->newTaskStatus = TaskStatus::defaultSlug();
    }

    /**
     * Livewire update requests never pass through the route's permission
     * middleware, so authorize on every render + every mutating action
     * (app-wide invariant — see CampaignStatus).
     */
    public function render()
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        // Columns are read live so a status an admin just added shows up with
        // no deploy. A value that no longer resolves (a column retired while
        // this form was open) is deliberately NOT silently rewritten — the
        // Rule::in() in createTask() reports it so the card cannot land in a
        // column the user did not pick.
        $statuses = $this->statusColumns();

        // Derived from the already-loaded columns rather than a second call to
        // TaskStatus::columns(), which would re-query inside a per-card loop.
        $completedSlugs = array_keys(
            array_filter($statuses, fn (array $column): bool => (bool) ($column['completed'] ?? false))
        );

        $tasks = Task::query()
            ->with('user:id,name')
            ->with('assignees:id,name')
            ->withCount('comments')
            ->when($this->boardId, fn ($q) => $q->where('board_id', $this->boardId))
            ->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.$this->search.'%'))
            ->when(
                $this->onlyMine && auth()->id(),
                fn ($q) => $q->whereHas('assignees', fn ($sub) => $sub->whereKey(auth()->id()))
            )
            ->when(
                $this->onlyOverdue,
                fn ($q) => $q->overdue($completedSlugs)
            )
            ->tap(fn ($q) => $this->applySort($q))
            ->get();

        // Overdue is computed here rather than in the query so the view needs
        // no knowledge of what counts as completed, and the value is ready for
        // both the card badge and the drawer without a second pass.
        $tasks->each(fn (Task $task) => $task->setAttribute('overdue', $task->isOverdue($completedSlugs)));

        return view('livewire.task-board', [
            // An archived board is not in the switcher even when you are looking
            // at one via its own URL — a tab list offering to jump you into
            // retired work is worse than no tab list. The board itself still
            // renders; boards/show carries the banner.
            'boards' => Board::query()->live()->withCount('tasks')->orderBy('id')->get(),
            'board' => $this->boardId ? Board::find($this->boardId) : null,
            'columns' => $statuses,
            'grouped' => $tasks->groupBy('status'),
            'columnCounts' => $this->columnCounts(),
            'filtersActive' => $this->filtersActive(),
            'openTask' => $this->openTaskRecord(),
            'openTaskActivity' => $this->openTaskActivity(),
            'assignableUsers' => $this->assignableUsers(),
            'completedSlugs' => $completedSlugs,
            'priorityLabels' => Task::PRIORITY_LABELS,
        ]);
    }

    /**
     * The live columns, read once per request.
     *
     * Memoised because two callers in one interaction need it - the WIP check
     * and the render that shows its result - and a status read is a query, not
     * a constant. Deliberately not cached across requests: a column an admin
     * adds or a ceiling they change has to be visible on the next interaction.
     *
     * @return array<string, array{name: string, color: string, completed: bool, limit: int|null}>
     */
    private function statusColumns(): array
    {
        return $this->statusColumns ??= TaskStatus::columns();
    }

    /**
     * True occupancy per column for the board on screen, ignoring the filters.
     *
     * Separate from the grouped card list on purpose. That list has been
     * narrowed by search, "only mine" and "past due", and a ceiling measured
     * against a filtered subset is a much friendlier number than the real one —
     * filtering to your own two cards would make a nine-card Review column
     * read 2/3. The point of a WIP limit is the unfriendly number.
     *
     * One grouped query rather than a COUNT per column, so the badge does not
     * make the board render cost grow with the number of columns.
     *
     * @return array<string, int>
     */
    private function columnCounts(): array
    {
        return Task::query()
            ->when($this->boardId, fn ($q) => $q->where('board_id', $this->boardId))
            ->groupBy('status')
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * Whether the visible card list is narrower than the board.
     *
     * Exists so the column header can say which of its two numbers is which:
     * with a filter on, "7" next to a column showing two cards needs a word
     * attached or it reads as a bug.
     */
    private function filtersActive(): bool
    {
        return $this->search !== '' || $this->onlyMine || $this->onlyOverdue;
    }

    /**
     * The advisory message for a column, or null when it is within its limit.
     *
     * Counts what the column holds *after* the change, not before, because the
     * moment worth interrupting is the drop that takes it over rather than a
     * pile-up that was already there when the user opened the board.
     *
     * Returns null for an unlimited column, and for a completed one - see
     * TaskStatus::effectiveLimit().
     */
    private function wipNoticeFor(string $slug): ?string
    {
        $limit = $this->statusColumns()[$slug]['limit'] ?? null;

        if ($limit === null) {
            return null;
        }

        $count = $this->columnCounts()[$slug] ?? 0;

        if ($count <= $limit) {
            return null;
        }

        return sprintf(
            '%s is over its work-in-progress limit (%d of %d). The move is allowed — but a pile-up here is the thing to fix, not the thing to work around.',
            TaskStatus::label($slug),
            $count,
            $limit,
        );
    }

    /**
     * The open card's history, newest first.
     *
     * Queried separately rather than eager-loaded onto openTaskRecord(). An
     * eager-loaded hasMany with a per-parent limit is a window function in most
     * drivers, and the drawer's need is simple enough to be worth stating
     * outright: the last 20 entries for one card. Capped because a card
     * created a year ago has a long log and the panel is a "what just happened"
     * glance, not an archive browser.
     */
    private function openTaskActivity(): Collection
    {
        if ($this->openTaskId === null) {
            return collect();
        }

        return TaskActivity::query()
            ->where('task_id', $this->openTaskId)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /**
     * Sorting is a view, never a write.
     *
     * 'manual' reads the stored position. The other two reorder by a column of
     * their own; the stored positions are left untouched so switching back
     * does not lose the arrangement the team built by dragging.
     */
    private function applySort(Builder $query): void
    {
        match ($this->sortMode) {
            // `due_at is null` is 0/1 in both MySQL and SQLite, so this lifts
            // undated cards below dated ones without a CASE expression.
            Task::SORT_DUE => $query
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->orderBy('position'),
            Task::SORT_PRIORITY => $query
                ->orderByDesc('priority')
                ->orderBy('position'),
            default => $query
                ->orderBy('position')
                ->orderBy('id'),
        };
    }

    /**
     * The card behind the detail drawer, or null when nothing is open.
     *
     * Scoped to the board currently on screen: the drawer is driven by an
     * `$openTaskId` the browser supplies, so without this a crafted payload
     * could open (and then comment on, or assign) a card from a board the
     * component was never rendered for. In this single-tenant app every board
     * is visible to a viewer anyway, so this is defence in depth, not a tenant
     * boundary - but it keeps the drawer's contents consistent with the board
     * behind it.
     */
    private function openTaskRecord(): ?Task
    {
        if ($this->openTaskId === null || $this->boardId === null) {
            return null;
        }

        return Task::query()
            ->whereKey($this->openTaskId)
            ->where('board_id', $this->boardId)
            ->with(['user:id,name', 'assignees:id,name', 'watchers:id,name'])
            ->with(['comments' => fn ($q) => $q->with('user:id,name')->orderBy('id')])
            ->first();
    }

    /**
     * People who can be put on a card. Active accounts only - assigning work to
     * a deactivated or deleted user is never what the picker meant.
     */
    private function assignableUsers()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function selectBoard(int $boardId): void
    {
        // Board switching is a read action (filtering), not a mutation — it
        // needs tasks.view, which render() already enforces on every render.
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        $this->boardId = $boardId;
        $this->reset('search');

        // A notice about another board's columns is worse than no notice.
        $this->wipNotice = null;
    }

    public function createTask(): void
    {
        abort_unless($this->canCreate(), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'newTaskStatus' => ['required', Rule::in(array_keys($this->statusColumns()))],
        ]);

        $this->withPositionRetry(function () use (&$task): void {
            // Append to the chosen column: the slot is the column's current card
            // count, and reserving it pushes any card already there up one. Two
            // people creating into the same empty-ish column at once both read
            // the same count, and the unique index turns the loser's write into a
            // retry rather than a silent tie.
            // withTrashed(): the unique index counts soft-deleted rows, so the slot
            // must too — otherwise appending after deleting the last card computes a
            // position a trashed row still occupies and collides on every retry.
            $columnCount = (int) Task::withTrashed()
                ->where('board_id', $this->boardId)
                ->where('status', $this->newTaskStatus)
                ->count();

            $newPosition = $this->reservePosition($this->boardId, $this->newTaskStatus, null, $columnCount);

            $task = Task::create([
                'user_id' => auth()->id(),
                'board_id' => $this->boardId,
                'title' => $this->title,
                'description' => $this->description,
                'status' => $this->newTaskStatus,
                'position' => $newPosition,
            ]);
        });

        $this->reset('title', 'description');

        // Filed straight into an over-limit column is the other way a ceiling
        // gets breached without anyone dragging anything.
        $this->wipNotice = $this->wipNoticeFor($task->status);

        TaskBoardChanged::dispatch(
            (int) $this->boardId,
            TaskBoardChanged::REASON_CREATED,
            $task->id,
            $this->actorId()
        );

        $this->dispatch('task-created', id: $task->id);
    }

    /**
     * Drag-and-drop target. The status is validated against the live
     * task_statuses table so a crafted Livewire payload can't write an
     * arbitrary status string — and so a column an admin just retired stops
     * being a valid drop target immediately.
     */
    #[On('task-moved')]
    public function moveTask(int $taskId, string $status, int $position): void
    {
        // Reordering another user's cards is an EDIT, not a create — a
        // tasks.create-only agent must not be able to shuffle the shared board
        // via a crafted Livewire payload.
        abort_unless($this->canMove(), 403);

        abort_unless(
            array_key_exists($status, $this->statusColumns()),
            422,
            'Unknown task status.'
        );

        $task = $this->taskOnCurrentBoardOrFail($taskId);

        $oldStatus = $task->status;

        // $position is a client-dispatched slot. The design intentionally allows a
        // slot PAST the column end (position = slot + 1; gaps are harmless), so we
        // must NOT clamp it to the column size — only defend against pathological
        // values: a negative slot (would shift every row and store a negative
        // position) or one near the integer-column ceiling (overflows on the
        // increment and escapes the unique-violation retry as a non-23000 error).
        $position = max(0, min($position, self::MAX_POSITION_SLOT));

        $sorted = $this->sortMode !== Task::SORT_MANUAL;

        if ($sorted) {
            // In a sorted view the on-screen drop index is not meaningful, so we
            // don't honour the visual slot. But a COLUMN CHANGE still has to write a
            // valid position in the destination: leaving the card's old position
            // untouched collides with whatever card already occupies that integer in
            // the new column (position is unique per (board, status)). Append to the
            // end of the destination column instead.
            if ($oldStatus !== $status) {
                $this->withPositionRetry(function () use ($task, $status): void {
                    $end = (int) Task::withTrashed()
                        ->where('board_id', $task->board_id)
                        ->where('status', $status)
                        ->count();
                    $newPosition = $this->reservePosition((int) $task->board_id, $status, $task->id, $end);
                    $task->update(['status' => $status, 'position' => $newPosition]);
                });
            }
        } else {
            $this->withPositionRetry(function () use ($task, $status, $position): void {
                // Reserve a free slot in the target column before writing. The
                // frontend sends the column's card count as the slot; reserving
                // shifts that slot's occupant (and everything after it) up one,
                // so the moved card never lands on an occupied integer. A
                // concurrent drag to the same slot collides on the unique index
                // and retries against fresh state instead of producing a tie.
                $newPosition = $this->reservePosition(
                    (int) $task->board_id,
                    $status,
                    $task->id,
                    $position
                );

                $task->update(['status' => $status, 'position' => $newPosition]);
            });
        }

        // Only fire the notification on a genuine transition — a re-order
        // inside the same column must not spam the owner's inbox.
        if ($oldStatus !== $status) {
            TaskStatusChanged::dispatch($task, $oldStatus, $status, $this->actorId());
        }

        // Checked after the write, so the number in the message is the column as
        // it now is. Assigned unconditionally rather than only when a limit is
        // breached, which also clears a notice left over from an earlier drop.
        $this->wipNotice = $this->wipNoticeFor($task->status);

        $this->dispatch('task-moved-ok');
    }

    /**
     * Put the WIP notice away.
     *
     * Writes nothing, so it needs no permission beyond the render() check —
     * the notice is advice, and advice the user cannot decline is nagging.
     */
    public function dismissWipNotice(): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        $this->wipNotice = null;
    }

    public function deleteTask(int $taskId): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.delete'), 403);

        // Resolve the card scoped to the board on screen (like moveTask/the drawer):
        // a crafted payload must not delete a card on a board the user isn't even
        // looking at. Unknown/other-board id 404s instead of silently succeeding.
        $task = $this->taskOnCurrentBoardOrFail($taskId);

        $boardId = (int) $task->board_id;

        $task->delete();

        TaskBoardChanged::dispatch($boardId, TaskBoardChanged::REASON_DELETED, $task->id, $this->actorId());

        // Never leave the drawer pointing at a card that no longer exists.
        if ($this->openTaskId === $task->id) {
            $this->closeTask();
        }
    }

    public function openTask(int $taskId): void
    {
        // render() already required tasks.view; re-checking keeps the component
        // consistent with every other action here.
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        // Scope to the current board before populating any drawer state, so a
        // crafted id can't seed the component snapshot with another board's card
        // detail. Resolve first, then commit openTaskId (a 404 leaves it untouched).
        $task = $this->taskOnCurrentBoardOrFail($taskId);

        $this->openTaskId = $task->id;
        $this->reset('commentBody');

        $this->detailDescription = (string) ($task->description ?? '');
        // Mirrored into the form here rather than bound straight to the model,
        // so abandoning the drawer leaves the card untouched.
        $this->detailDueAt = $this->formatForInput($task->due_at);
        $this->detailPriority = (int) ($task->priority ?? Task::PRIORITY_NORMAL);
        $this->detailEstimate = $task->estimate_minutes !== null ? (string) $task->estimate_minutes : '';
    }

    /** The value shape an <input type="datetime-local"> expects. */
    private function formatForInput(?Carbon $moment): string
    {
        return $moment?->format('Y-m-d\TH:i') ?? '';
    }

    public function closeTask(): void
    {
        $this->openTaskId = null;
        $this->reset('commentBody', 'detailDescription', 'detailDueAt', 'detailEstimate');
    }

    public function toggleOnlyMine(): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        $this->onlyMine = ! $this->onlyMine;
    }

    public function toggleOnlyOverdue(): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        $this->onlyOverdue = ! $this->onlyOverdue;
    }

    /**
     * Change how the board is ordered.
     *
     * A view control, so tasks.view is enough — it writes nothing. The mode is
     * validated rather than trusted because it decides the SQL order applied to
     * every render, and an unrecognised value must not silently fall through to
     * a match arm.
     */
    public function setSortMode(string $mode): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        abort_unless(in_array($mode, Task::SORT_MODES, true), 422, 'Unknown sort mode.');

        $this->sortMode = $mode;
    }

    /**
     * Put someone on (or take them off) a card.
     *
     * Requires tasks.create, not tasks.edit. Assignment is the same class of
     * act as creating a card - putting work into the system - and gating it on
     * tasks.edit would mean an agent could raise a card but not hand it to
     * anyone, which is the opposite of how the board is meant to be used. The
     * tasks.edit split exists to stop a card being *reshuffled* under people,
     * and naming an owner is not that.
     */
    public function toggleAssignee(int $userId): void
    {
        abort_unless($this->canCreate(), 403);

        $task = $this->openTaskOrFail();
        $user = $this->findAssignableUserOrFail($userId);

        $task->assignees()->toggle($user->id);

        $this->pushBoardChanged($task, TaskBoardChanged::REASON_ASSIGNED);
    }

    /**
     * Watch a card. Watching is strictly self-service: it only controls what you
     * are notified about, and the id supplied must be your own. Passing anyone
     * else's id is a 403, not a silent no-op — watching on another user's behalf
     * would be a notification preference being set by the wrong person.
     */
    public function toggleWatcher(int $userId): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);
        abort_if($userId !== auth()->id(), 403);

        $task = $this->openTaskOrFail();
        $user = $this->findAssignableUserOrFail($userId);

        $task->watchers()->toggle($user->id);
    }

    public function saveDescription(): void
    {
        // Rewriting a card's description is an edit to shared content, so it
        // takes tasks.edit - the same authority that moves the card.
        abort_unless($this->canMove(), 403);

        // State is checked before input: a call with no card open is a bad
        // request, not a validation failure, and reporting it as "description
        // is required" would send the user looking in the wrong place.
        $task = $this->openTaskOrFail();

        $this->validate([
            'detailDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        $task->update(['description' => $this->detailDescription]);

        $this->pushBoardChanged($task, TaskBoardChanged::REASON_UPDATED);
    }

    /**
     * Save deadline, priority and estimate.
     *
     * Takes tasks.edit, the same authority as the description and the move:
     * these fields decide what gets worked on and when, so changing them is a
     * change to shared content rather than something a tasks.view-only reader
     * should be able to do.
     */
    public function saveTriage(): void
    {
        abort_unless($this->canMove(), 403);

        // State before input, for the same reason as saveDescription().
        $task = $this->openTaskOrFail();

        $this->validate([
            'detailDueAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'detailPriority' => ['required', 'integer', Rule::in(array_keys(Task::PRIORITY_LABELS))],
            // Present-but-empty must clear the estimate rather than fail on
            // "required", so it is nullable rather than sometimes-required.
            'detailEstimate' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $task->update([
            // An empty datetime-local posts '' — stored as null, not the epoch.
            'due_at' => $this->detailDueAt === ''
                ? null
                : Carbon::createFromFormat('Y-m-d\TH:i', $this->detailDueAt),
            'priority' => (int) $this->detailPriority,
            'estimate_minutes' => $this->detailEstimate === ''
                ? null
                : (int) $this->detailEstimate,
        ]);

        // Its own reason, so a changed deadline is never announced as a text
        // edit. "You moved the due date" is the fact people act on.
        $this->pushBoardChanged($task, TaskBoardChanged::REASON_TRIAGE_UPDATED);
    }

    /**
     * Post a comment.
     *
     * Gated on tasks.view rather than tasks.create/edit: the board is a shared
     * communication surface, and being able to read a card implies being able
     * to discuss it. Comments change no board structure, so they are not the
     * kind of shared mutation tasks.edit exists to gate. If that is too loose
     * for this team, the one line to change is here.
     */
    public function addComment(): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        // State before input, for the same reason as saveDescription().
        $task = $this->openTaskOrFail();

        $this->validate([
            'commentBody' => ['required', 'string', 'max:2000'],
        ]);

        $task->comments()->create([
            'user_id' => auth()->id(),
            'message' => $this->commentBody,
        ]);

        $this->reset('commentBody');

        $this->pushBoardChanged($task, TaskBoardChanged::REASON_COMMENTED);
    }

    /**
     * Delete a comment. Authors may retract their own; anything wider needs
     * tasks.delete, the same authority that removes the card itself.
     */
    public function deleteComment(int $commentId): void
    {
        abort_unless((bool) auth()->user()?->can('tasks.view'), 403);

        $task = $this->openTaskOrFail();

        $comment = $task->comments()->whereKey($commentId)->first();

        abort_if($comment === null, 404);

        abort_unless(
            $comment->user_id === auth()->id() || (bool) auth()->user()?->can('tasks.delete'),
            403
        );

        $comment->delete();

        // Not REASON_COMMENTED: a retraction must not read as an arrival.
        $this->pushBoardChanged($task, TaskBoardChanged::REASON_COMMENT_REMOVED);
    }

    /**
     * The open card, or a 404 if the drawer is not showing one. Every drawer
     * action goes through this so a stale or spoofed $openTaskId cannot act on
     * a card outside the board on screen.
     */
    private function openTaskOrFail(): Task
    {
        abort_unless($this->openTaskId !== null, 422, 'No task is open.');

        $task = $this->openTaskRecord();

        abort_if($task === null, 404);

        return $task;
    }

    /**
     * Resolve a card by id, refusing one that is not on the board being viewed.
     *
     * `canMove()` is a global permission, so on its own it does not stop a
     * crafted payload naming any task in the system. Every board is visible to
     * the same people here, so this is not privilege escalation between groups -
     * but it means a drag could act on a card the user cannot see, and (once the
     * activity log exists) write a history entry on a board they were not even
     * looking at. That is exactly the sort of unexplainable row that teaches
     * people not to trust the history, so the lookup is scoped like the
     * drawer's.
     */
    private function taskOnCurrentBoardOrFail(int $taskId): Task
    {
        abort_unless($this->boardId !== null, 422, 'No board is selected.');

        $task = Task::query()
            ->whereKey($taskId)
            ->where('board_id', $this->boardId)
            ->first();

        abort_if($task === null, 404);

        return $task;
    }

    /**
     * Resolve a person to attach, refusing anything that is not an active
     * user. Prevents a crafted payload from writing a pivot row for a deleted
     * or deactivated account.
     */
    private function findAssignableUserOrFail(int $userId): User
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        abort_if($user === null, 404);

        return $user;
    }

    /**
     * Tell everyone else on the board this card changed. No-op when the drawer
     * is closed, since there is no card to attribute the change to.
     */
    private function pushBoardChanged(Task $task, string $reason): void
    {
        TaskBoardChanged::dispatch((int) $task->board_id, $reason, $task->id, $this->actorId());
    }

    /**
     * The user responsible for the change, carried on the events so the
     * notification resolver can skip emailing them about their own action.
     *
     * Null when the component runs without an authenticated user, which is
     * possible in tests and in any future console-driven path. The resolver
     * treats null as "nobody to exclude".
     */
    private function actorId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * Another user changed this board (Echo push from TaskStatusChanged or
     * TaskBoardChanged). Re-render so their card, move or deletion appears
     * here without a reload.
     *
     * render() re-reads the board from the database and does not re-apply the
     * remote change, so there is nothing to merge; authorization still runs
     * because every Livewire request passes back through render().
     */
    #[On('task-board:refresh')]
    public function refreshFromBroadcast(): void
    {
        //
    }

    /**
     * Reserve a free slot in a column and return its 1-based position.
     *
     * `$slot` is the 0-based insertion index the frontend sends (the card count
     * in the column at drop time). Everything already sitting at or past that
     * slot is pushed up one, which frees the slot so the card being placed never
     * lands on an occupied integer. The card being placed is excluded from the
     * shift so a reorder does not fight its own old row.
     *
     * Gaps are left where a card vacates — harmless for ordering. The point of
     * the unique index is to stop *ties*, which is what a drag that collided
     * silently produced before this.
     */
    private function reservePosition(int $boardId, string $status, ?int $excludeTaskId, int $slot): int
    {
        $position = $slot + 1;

        // withTrashed() is essential: the unique (board_id, status, position) index
        // counts soft-deleted rows, but Eloquent's default scope hides them. If the
        // shift skipped trashed rows, a trashed card frozen at `position` would not
        // move aside and the insert/update would collide with it — deterministically,
        // e.g. after deleting the last card in a column and adding a new one. Shifting
        // trashed rows too keeps the live position-space consistent with the index.
        Task::withTrashed()
            ->where('board_id', $boardId)
            ->where('status', $status)
            ->when($excludeTaskId !== null, fn (Builder $q): Builder => $q->whereKeyNot($excludeTaskId))
            ->where('position', '>=', $position)
            ->increment('position');

        return $position;
    }

    /**
     * Run a positioning write inside a transaction, retrying if two drags collide.
     *
     * The unique `(board_id, status, position)` index is the backstop: a
     * concurrent drag that read the same slot as this one is rejected with a
     * duplicate-key error instead of silently tying. Re-running recomputes the
     * slot against the now-current state, so the retry lands cleanly. Only a
     * duplicate key is retried — anything else is a real error.
     */
    private function withPositionRetry(callable $operation): void
    {
        $attempts = 0;

        do {
            try {
                DB::transaction($operation);

                return;
            } catch (QueryException $e) {
                if ($this->isUniqueKeyViolation($e) && $attempts < 5) {
                    $attempts++;

                    continue;
                }

                throw $e;
            }
        } while ($attempts < 5);
    }

    private function isUniqueKeyViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }

    private function canCreate(): bool
    {
        return (bool) auth()->user()?->can('tasks.create');
    }

    private function canMove(): bool
    {
        return (bool) auth()->user()?->can('tasks.edit');
    }
}
