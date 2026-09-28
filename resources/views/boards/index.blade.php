<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Boards</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-4 sm:px-6 lg:px-8">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-gray-500">
                @if($showArchived)
                    Showing archived boards.
                @elseif($archivedCount)
                    <a href="{{ route('boards.index', ['archived' => 1]) }}"
                       class="text-gray-700 underline hover:text-gray-900">
                        {{ $archivedCount }} archived {{ Str::plural('board', $archivedCount) }}
                    </a>
                @endif
            </div>

            <div class="flex items-center gap-3">
                @if($showArchived)
                    <a href="{{ route('boards.index') }}"
                       class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back to live boards
                    </a>
                @endif

                @can('tasks.create')
                    <a href="{{ route('boards.create') }}"
                       class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                        New board
                    </a>
                @endcan
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($boards as $board)
                {{-- A <div>, not an <a>: the card now carries forms, and a form
                     inside a link is not valid HTML and does not survive being
                     clicked. The title stays the link. --}}
                <div @class([
                    'rounded-lg border bg-white p-4 shadow-sm transition',
                    'border-gray-200 hover:border-gray-400' => ! $board->archived_at,
                    'border-amber-200 bg-amber-50/40' => $board->archived_at,
                ])>
                    <a href="{{ route('boards.show', $board) }}" class="block">
                        <h3 class="flex items-center gap-2 font-semibold text-gray-900">
                            {{ $board->name }}
                            @if($board->archived_at)
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                    Archived
                                </span>
                            @endif
                        </h3>
                        @if($board->description)
                            <p class="mt-1 line-clamp-2 text-sm text-gray-500">{{ $board->description }}</p>
                        @endif
                    </a>

                    <p class="mt-3 text-xs text-gray-400">
                        {{ $board->tasks_count }} {{ Str::plural('task', $board->tasks_count) }}
                        · created by {{ $board->owner?->name ?? '—' }}
                        @if($board->archived_at)
                            · archived {{ $board->archived_at->format('j M Y') }}
                        @endif
                    </p>

                    @can('tasks.delete')
                        {{-- Archive is offered before Delete, and for good reason:
                             deleting a board soft-deletes every card on it and
                             nothing in the UI can bring them back. Archiving
                             hides the board and keeps all of it. --}}
                        <div class="mt-3 flex items-center gap-3 border-t border-gray-100 pt-3">
                            @can('tasks.create')
                                <form method="POST" action="{{ route('boards.duplicate', $board) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-gray-700 hover:text-gray-900">
                                        Duplicate
                                    </button>
                                </form>
                            @endcan

                            @if($board->archived_at)
                                <form method="POST" action="{{ route('boards.unarchive', $board) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-gray-700 hover:text-gray-900">
                                        Restore
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('boards.archive', $board) }}">
                                    @csrf
                                    <button type="submit"
                                            class="text-xs font-medium text-gray-700 hover:text-gray-900">
                                        Archive
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('boards.destroy', $board) }}"
                                      onsubmit="return confirm('Delete {{ $board->name }} and its {{ $board->tasks_count }} cards? This cannot be undone from the app.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                                        Delete
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endcan
                </div>
            @empty
                <p class="text-sm text-gray-500">
                    {{ $showArchived ? 'No archived boards.' : 'No boards yet.' }}
                </p>
            @endforelse
        </div>
    </div>
</x-app-layout>
