<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">My tasks</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 lg:px-8 space-y-6">
        @if($total === 0)
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="font-display text-lg font-semibold text-gray-900">Nothing assigned to you</p>
                <p class="mt-1 text-sm text-gray-500">When someone gives you a task, it appears here and you get an email.</p>
                <a href="{{ route('tasks.index') }}" class="mt-4 inline-block text-sm font-medium text-brand-text underline">Open the board</a>
            </div>
        @else
            <p class="text-sm text-gray-500">{{ $total }} open {{ \Illuminate\Support\Str::plural('task', $total) }} assigned to you, across all boards.</p>

            @foreach($groups as $label => $tasks)
                @continue($tasks->isEmpty())

                <section aria-labelledby="group-{{ \Illuminate\Support\Str::slug($label) }}">
                    <h3 id="group-{{ \Illuminate\Support\Str::slug($label) }}"
                        class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-wide {{ $label === 'Overdue' ? 'text-red-600' : 'text-gray-500' }}">
                        {{ $label }}
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">{{ $tasks->count() }}</span>
                    </h3>

                    <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
                        @foreach($tasks as $task)
                            <li>
                                <a href="{{ route('boards.show', $task->board) }}" wire:navigate
                                   class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-gray-50">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-900">{{ $task->title }}</span>
                                        <span class="block truncate text-xs text-gray-500">
                                            {{ $task->board->name }}
                                            @if($task->user) · from {{ $task->user->name }} @endif
                                        </span>
                                    </span>

                                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">{{ \App\Models\TaskStatus::label($task->status) }}</span>

                                    @if($task->priority >= \App\Models\Task::PRIORITY_HIGH)
                                        <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ $task->priorityLabel() }}</span>
                                    @endif

                                    @if($task->due_at)
                                        <span class="text-xs {{ $label === 'Overdue' ? 'font-semibold text-red-600' : 'text-gray-500' }}">
                                            Due {{ $task->due_at->format('D j M, H:i') }}
                                        </span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </div>
</x-app-layout>
