<div class="flex h-full flex-col gap-4 p-4">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach($boards as $b)
                <button
                    wire:click="selectBoard({{ $b->id }})"
                    wire:key="board-tab-{{ $b->id }}"
                    @class([
                        'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                        'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $b->id !== $boardId,
                        'border-gray-900 bg-gray-900 text-white' => $b->id === $boardId,
                    ])
                >
                    {{ $b->name }}
                    <span class="opacity-60">({{ $b->tasks_count }})</span>
                </button>
            @endforeach
        </div>

        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search tasks…"
            class="w-56 rounded-md border-gray-300 text-sm shadow-sm"
        />

        {{-- The point of the assignee pivot: "what is mine" in one click. --}}
        <button
            type="button"
            wire:click="toggleOnlyMine"
            @class([
                'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                'border-gray-900 bg-gray-900 text-white' => $onlyMine,
                'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $onlyMine,
            ])
        >
            Only mine
        </button>

        {{-- Overdue styling is useless without a way to find them. --}}
        <button
            type="button"
            wire:click="toggleOnlyOverdue"
            @class([
                'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                'border-red-600 bg-red-600 text-white' => $onlyOverdue,
                'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $onlyOverdue,
            ])
        >
            Overdue only
        </button>

        <div class="ml-auto flex items-center gap-2">
            <label for="sort-mode" class="text-xs font-medium text-gray-600">Sort</label>
            {{-- wire:change rather than wire:model so the validated action is
                 the only path that can set this. wire:model would write the
                 property directly and skip the Rule::in()-equivalent check. --}}
            <select id="sort-mode" wire:change="setSortMode($event.target.value)"
                    @class([
                        'rounded-md border-gray-300 text-sm shadow-sm',
                        'border-amber-400 bg-amber-50' => $sortMode !== \App\Models\Task::SORT_MANUAL,
                    ])>
                <option value="manual">Manual (drag order)</option>
                <option value="due">Due date</option>
                <option value="priority">Priority</option>
            </select>
        </div>
    </div>

    @if($sortMode !== \App\Models\Task::SORT_MANUAL)
        {{-- A sorted view ignores the drop index; saying so beats letting
             someone drag a card and watch it snap back. --}}
        <p class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Cards are sorted by {{ $sortMode === 'due' ? 'due date' : 'priority' }}.
            You can still drag a card into another column, but the order within a column is managed for you.
        </p>
    @endif

    @if($board)
        <form wire:submit="createTask" class="flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-white p-3">
            <div class="min-w-[16rem] flex-1">
                <label for="task-title" class="mb-1 block text-xs font-medium text-gray-600">Title</label>
                <input id="task-title" type="text" wire:model="title"
                       class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                       placeholder="What needs doing?">
                @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="min-w-[14rem] flex-1">
                <label for="task-desc" class="mb-1 block text-xs font-medium text-gray-600">Description</label>
                <input id="task-desc" type="text" wire:model="description"
                       class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                       placeholder="Optional detail">
            </div>

            <div>
                <label for="task-status" class="mb-1 block text-xs font-medium text-gray-600">Status</label>
                <select id="task-status" wire:model="newTaskStatus" class="rounded-md border-gray-300 text-sm shadow-sm">
                    @foreach($columns as $key => $col)
                        <option value="{{ $key }}">{{ $col['name'] }}</option>
                    @endforeach
                </select>
            </div>

            @can('tasks.create')
                <button type="submit"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Add task
                </button>
            @endcan
        </form>
    @else
        <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
            No boards yet. Create one from the Boards page.
        </div>
    @endif

    {{-- Status colour tokens are admin-editable, so the Tailwind classes must
         be literal strings (the JIT cannot see a dynamic bg-{{ $token }}-500).
         Unknown tokens fall back to gray. --}}
    @php
        $dotClasses = [
            'gray' => 'bg-gray-400',
            'red' => 'bg-red-500',
            'amber' => 'bg-amber-500',
            'yellow' => 'bg-yellow-500',
            'green' => 'bg-green-500',
            'emerald' => 'bg-emerald-500',
            'teal' => 'bg-teal-500',
            'sky' => 'bg-sky-500',
            'blue' => 'bg-blue-500',
            'indigo' => 'bg-indigo-500',
            'violet' => 'bg-violet-500',
            'pink' => 'bg-pink-500',
            'rose' => 'bg-rose-500',
        ];
    @endphp

    {{-- Advisory, never a block: the move already happened. Amber rather than
         red because nothing is wrong, and a limit that punishes the drop is a
         limit people route around. --}}
    @if($wipNotice)
        <div wire:key="wip-notice"
             class="mb-4 flex items-start justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3"
             role="status">
            <p class="text-sm text-amber-900">{{ $wipNotice }}</p>
            <button type="button" wire:click="dismissWipNotice"
                    class="shrink-0 text-sm text-amber-700 hover:text-amber-900"
                    title="Dismiss">&times;</button>
        </div>
    @endif

    <div class="grid flex-1 grid-cols-1 gap-4 overflow-x-auto md:grid-cols-2 xl:grid-cols-4">
        @foreach($columns as $key => $col)
            <section
                wire:key="column-{{ $key }}"
                class="flex min-h-[12rem] flex-col rounded-lg bg-gray-100/70 p-3"
                data-column="{{ $key }}"
            >
                @php
                    // True occupancy, deliberately not the filtered card count
                    // below: a ceiling measured against a filtered subset would
                    // make a nine-card Review column read 2/3 under a filter.
                    $wipCount = $columnCounts[$key] ?? 0;
                    $wipLimit = $col['limit'] ?? null;
                @endphp
                <header class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $dotClasses[$col['color']] ?? $dotClasses['gray'] }}"></span>
                        {{ $col['name'] }}
                        @if($col['completed'] ?? false)
                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
                                Done
                            </span>
                        @endif
                    </h3>
                    <div class="flex shrink-0 items-center gap-1.5">
                        {{-- Only rendered when a ceiling is actually set, so a
                             board nobody has constrained looks exactly as it
                             did before. --}}
                        @if($wipLimit !== null)
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                                'bg-amber-100 text-amber-900 ring-1 ring-amber-300' => $wipCount > $wipLimit,
                                'bg-white text-gray-500 ring-1 ring-gray-200' => $wipCount <= $wipLimit,
                            ])
                                  title="{{ $wipCount > $wipLimit
                                      ? $wipCount - $wipLimit . ' over the limit. Moves are still allowed.'
                                      : 'Work-in-progress limit. Moves are still allowed.' }}">
                                {{ $wipCount }}/{{ $wipLimit }}
                            </span>
                        @endif
                        <span @if($filtersActive) title="Cards shown — a filter is on, so this is fewer than the column holds" @endif
                              class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-600">
                            {{ ($grouped[$key] ?? collect())->count() }}
                        </span>
                    </div>
                </header>

                <div class="flex-1 space-y-2"
                     data-dropzone="{{ $key }}"
                     wire:ignore.self>
                    @forelse($grouped[$key] ?? [] as $task)
                        <article wire:key="task-{{ $task->id }}"
                                 draggable="true"
                                 data-task-id="{{ $task->id }}"
                                 @class([
                                     'cursor-grab rounded-md border bg-white p-3 shadow-sm',
                                     // Overdue is the one card state that has to be
                                     // visible at a glance, so it colours the whole
                                     // card rather than adding another badge to
                                     // scan for.
                                     'border-red-300 ring-1 ring-red-200' => $task->overdue,
                                     'border-gray-200 hover:border-gray-400' => ! $task->overdue,
                                 ])>
                            <button type="button"
                                    wire:click="openTask({{ $task->id }})"
                                    class="block w-full text-left">
                                <p class="text-sm font-medium text-gray-900">{{ $task->title }}</p>

                                @if($task->description)
                                    <p class="mt-1 line-clamp-3 text-xs text-gray-500">{{ $task->description }}</p>
                                @endif
                            </button>

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @if($task->overdue)
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700">
                                        Overdue {{ $task->due_at?->diffForHumans(short: true) }}
                                    </span>
                                @endif

                                @if($task->priority !== \App\Models\Task::PRIORITY_NORMAL)
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                        'bg-red-100 text-red-700' => $task->priority === \App\Models\Task::PRIORITY_URGENT,
                                        'bg-amber-100 text-amber-800' => $task->priority === \App\Models\Task::PRIORITY_HIGH,
                                        'bg-gray-100 text-gray-600' => $task->priority < \App\Models\Task::PRIORITY_NORMAL,
                                    ])>
                                        {{ $task->priorityLabel() }}
                                    </span>
                                @endif

                                @if($task->due_at && ! $task->overdue)
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">
                                        Due {{ $task->due_at->format('j M') }}
                                    </span>
                                @endif

                                @foreach($task->assignees as $assignee)
                                    <span wire:key="task-{{ $task->id }}-assignee-{{ $assignee->id }}"
                                          title="Assigned to {{ $assignee->name }}"
                                          class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-800 text-[10px] font-semibold uppercase text-white">
                                        {{ $assignee->initials() }}
                                    </span>
                                @endforeach

                                @if($task->comments_count > 0)
                                    <span class="text-xs text-gray-400">{{ $task->comments_count }} comment{{ $task->comments_count === 1 ? '' : 's' }}</span>
                                @endif
                            </div>

                            <div class="mt-2 flex items-center justify-between text-xs text-gray-400">
                                <span>{{ $task->user?->name ?? '—' }}</span>
                                <span>{{ $task->updated_at?->diffForHumans() }}</span>
                            </div>

                            @can('tasks.delete')
                                <button wire:click="deleteTask({{ $task->id }})"
                                        wire:confirm="Delete this task?"
                                        class="mt-2 text-xs text-red-600 hover:underline">
                                    Delete
                                </button>
                            @endcan
                        </article>
                    @empty
                        <p class="py-6 text-center text-xs text-gray-400">Drop tasks here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    {{-- Card detail. Rendered outside the board grid so a long comment thread
         cannot stretch a column, and keyed so switching cards does not leave
         the previous card's scroll position or a stale comment box behind. --}}
    @if($openTask)
        <div wire:key="drawer-{{ $openTask->id }}"
             class="fixed inset-x-0 bottom-0 z-40 max-h-[70vh] overflow-y-auto rounded-t-xl border-t border-gray-200 bg-white p-4 shadow-2xl">
            <div class="mx-auto max-w-3xl">
                <div class="mb-3 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ $openTask->title }}</h2>
                        <p class="text-xs text-gray-500">
                            Raised by {{ $openTask->user?->name ?? '—' }}
                            · {{ $openTask->updated_at?->diffForHumans() }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeTask"
                            class="rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">
                        Close
                    </button>
                </div>

                <section class="mb-4">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Due date, priority and estimate</h3>

                    @can('tasks.edit')
                        <form wire:submit="saveTriage" class="flex flex-wrap items-end gap-3">
                            <div>
                                <label for="detail-due" class="mb-1 block text-xs text-gray-600">Due</label>
                                <input id="detail-due" type="datetime-local" wire:model="detailDueAt"
                                       class="rounded-md border-gray-300 text-sm shadow-sm">
                                @error('detailDueAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="detail-priority" class="mb-1 block text-xs text-gray-600">Priority</label>
                                <select id="detail-priority" wire:model="detailPriority"
                                        class="rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach($priorityLabels as $level => $label)
                                        <option value="{{ $level }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('detailPriority') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="detail-estimate" class="mb-1 block text-xs text-gray-600">Estimate (min)</label>
                                <input id="detail-estimate" type="number" min="0" step="5" wire:model="detailEstimate"
                                       class="w-28 rounded-md border-gray-300 text-sm shadow-sm"
                                       placeholder="Optional">
                                @error('detailEstimate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                Save
                            </button>
                        </form>
                    @else
                        {{-- The drawer is loaded by openTaskRecord(), not the
                             list query, so it has no precomputed `overdue`
                             attribute - compute it from the same completed slugs. --}}
                        <p class="text-sm text-gray-700">
                            @if($openTask->isOverdue($completedSlugs))
                                <span class="font-semibold text-red-700">Overdue</span> ·
                            @endif
                            {{ $openTask->priorityLabel() }} priority
                            @if($openTask->due_at) · due {{ $openTask->due_at->format('j M Y, H:i') }} @endif
                            @if($openTask->estimate_minutes) · {{ $openTask->estimate_minutes }} min estimate @endif
                        </p>
                    @endcan
                </section>

                <section class="mb-4">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Description</h3>

                    @can('tasks.edit')
                        <form wire:submit="saveDescription">
                            <textarea wire:model="detailDescription" rows="3"
                                      class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                                      placeholder="Add more detail for whoever picks this up"></textarea>
                            @error('detailDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <button type="submit" class="mt-1 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                Save description
                            </button>
                        </form>
                    @else
                        <p class="whitespace-pre-line text-sm text-gray-700">{{ $openTask->description ?: 'No description.' }}</p>
                    @endcan
                </section>

                <div class="mb-4 grid gap-4 sm:grid-cols-2">
                    <section>
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Assignees</h3>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse($assignableUsers as $person)
                                @php($isAssigned = $openTask->assignees->contains('id', $person->id))
                                <button type="button"
                                        wire:key="assignee-{{ $person->id }}"
                                        wire:click="toggleAssignee({{ $person->id }})"
                                        @class([
                                            'rounded-full border px-2.5 py-1 text-xs transition',
                                            'border-gray-900 bg-gray-900 text-white' => $isAssigned,
                                            'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isAssigned,
                                        ])>
                                    {{ $person->name }}
                                </button>
                            @empty
                                <p class="text-xs text-gray-400">No active users to assign.</p>
                            @endforelse
                        </div>
                    </section>

                    <section>
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Watching</h3>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox"
                                   wire:click="toggleWatcher({{ auth()->id() }})"
                                   @checked($openTask->watchers->contains('id', auth()->id()))>
                            Notify me about updates to this card
                        </label>
                        @if ($openTask->watchers->isNotEmpty())
                            <p class="mt-1 text-xs text-gray-400">
                                Watching: {{ $openTask->watchers->pluck('name')->join(', ') }}
                            </p>
                        @endif
                    </section>
                </div>

                <section>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Comments ({{ $openTask->comments->count() }})
                    </h3>

                    <ul class="mb-3 space-y-2">
                        @forelse($openTask->comments as $comment)
                            <li wire:key="comment-{{ $comment->id }}"
                                class="rounded-md border border-gray-200 bg-gray-50 p-2">
                                <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">{{ $comment->user?->name ?? '—' }}</span>
                                    <span class="flex items-center gap-2">
                                        {{ $comment->created_at?->diffForHumans() }}
                                        @if($comment->user_id === auth()->id() || auth()->user()?->can('tasks.delete'))
                                            <button type="button"
                                                    wire:click="deleteComment({{ $comment->id }})"
                                                    wire:confirm="Delete this comment?"
                                                    class="text-red-600 hover:underline">
                                                Delete
                                            </button>
                                        @endif
                                    </span>
                                </div>
                                <p class="whitespace-pre-line text-sm text-gray-800">{{ $comment->message }}</p>
                            </li>
                        @empty
                            <li class="text-xs text-gray-400">No comments yet.</li>
                        @endforelse
                    </ul>

                    <form wire:submit="addComment" class="flex flex-col gap-2 sm:flex-row">
                        <input type="text" wire:model="commentBody"
                               class="flex-1 rounded-md border-gray-300 text-sm shadow-sm"
                               placeholder="Add a comment…">
                        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            Comment
                        </button>
                    </form>
                    @error('commentBody') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </section>

                <section>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        History
                        <span class="font-normal normal-case text-gray-400">
                            (last {{ $openTaskActivity->count() }})
                        </span>
                    </h3>

                    {{-- A record of what happened and who did it, written by
                         listeners on the board events. This is the difference
                         between "a comment mentions it was moved" and being
                         able to see it. Capped server-side; the count here is
                         the rows actually loaded, not the full log, so it
                         reads as "what's shown" rather than claiming to be
                         the total. --}}
                    <ul class="space-y-1.5">
                        @forelse($openTaskActivity as $entry)
                            <li wire:key="activity-{{ $entry->id }}"
                                class="flex items-baseline justify-between gap-3 border-l-2 border-gray-200 pl-2 text-xs">
                                <span class="text-gray-700">
                                    <span class="font-medium">{{ $entry->user?->name ?? 'Someone' }}</span>
                                    {{ $entry->summary($entry->user?->name) }}
                                </span>
                                <span class="shrink-0 text-gray-400">{{ $entry->created_at?->diffForHumans() }}</span>
                            </li>
                        @empty
                            <li class="text-xs text-gray-400">Nothing recorded yet.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
    // Vanilla HTML5 drag-and-drop → Livewire. Kept dependency-free so no new
    // npm package is required; the sortable library can replace this later
    // without touching the PHP component contract (`task-moved`).
    document.addEventListener('livewire:init', () => {
        let dragged = null;

        document.addEventListener('dragstart', (e) => {
            const card = e.target.closest('[data-task-id]');
            if (!card) return;
            dragged = card;
            card.classList.add('opacity-50');
            // Tell app.js a gesture is in flight so a teammate's broadcast
            // cannot re-render the board and rip the card out from under the
            // pointer mid-drag.
            window.bqBoardDragActive = true;
        });

        document.addEventListener('dragend', (e) => {
            e.target.closest('[data-task-id]')?.classList.remove('opacity-50');
            // Also covers a gesture cancelled outside any column, so the flag
            // can never latch on and freeze board refreshes.
            window.bqBoardDragActive = false;
        });

        document.addEventListener('dragover', (e) => {
            const zone = e.target.closest('[data-dropzone]');
            if (!zone || !dragged) return;
            e.preventDefault();
        });

        document.addEventListener('drop', (e) => {
            const zone = e.target.closest('[data-dropzone]');
            if (!zone || !dragged) return;
            e.preventDefault();

            const status = zone.dataset.dropzone;
            const position = zone.querySelectorAll('[data-task-id]').length;
            const taskId = Number(dragged.dataset.taskId);

            dragged = null;
            window.bqBoardDragActive = false;

            Livewire.dispatch('task-moved', {
                taskId: taskId,
                status: status,
                position: position,
            });
        });
    });
</script>
@endpush
