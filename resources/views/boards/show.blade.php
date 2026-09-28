<x-app-layout>
    {{-- Lets resources/js/app.js subscribe to this board's private Echo
         channel, so a teammate's drag updates the board in place. --}}
    @push('head')
        <meta name="task-board-id" content="{{ $board->id }}">
    @endpush

    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="flex items-center gap-2 text-xl font-semibold leading-tight text-gray-800">
                {{ $board->name }}
                @if($board->archived_at)
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-amber-800">
                        Archived
                    </span>
                @endif
            </h2>
            <div class="flex items-center gap-2">
                @can('tasks.create')
                    <form method="POST" action="{{ route('boards.duplicate', $board) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Duplicate
                        </button>
                    </form>
                @endcan

                @can('tasks.delete')
                    @if($board->archived_at)
                        <form method="POST" action="{{ route('boards.unarchive', $board) }}">
                            @csrf
                            <button type="submit"
                                    class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800">
                                Restore board
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('boards.archive', $board) }}">
                            @csrf
                            <button type="submit"
                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Archive
                            </button>
                        </form>
                    @endif
                @endcan

                @can('tasks.report')
                    <a href="{{ route('boards.report', $board) }}"
                       class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Report
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-[1600px] mx-auto sm:px-6 lg:px-8">
        {{-- An archived board still renders rather than redirecting: a stale
             bookmark should land somewhere that says why it is not in the list,
             not somewhere that silently bounces. Nothing on it is deleted. --}}
        @if($board->archived_at)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3">
                <p class="text-sm text-amber-900">
                    This board was archived
                    {{ $board->archived_at->format('j M Y') }} and is out of the working list.
                    Its {{ $board->tasks()->count() }} {{ Str::plural('card', $board->tasks()->count()) }},
                    history and activity log are all intact.
                </p>
                <a href="{{ route('boards.index') }}" class="text-sm font-medium text-amber-800 underline">
                    All boards
                </a>
            </div>
        @endif

        @livewire('task-board', ['board' => $board])
    </div>
</x-app-layout>
