<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit board</h2>
    </x-slot>

    <div class="py-6 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('boards.update', $board) }}"
              class="space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="name" name="name" type="text" required
                       value="{{ old('name', $board->name) }}"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea id="description" name="description" rows="3"
                          class="mt-1 w-full rounded-md border-gray-300 shadow-sm">{{ old('description', $board->description) }}</textarea>
                @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('boards.show', $board) }}"
                   class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700">Cancel</a>
                <button type="submit"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Save changes
                </button>
            </div>
        </form>

        @can('tasks.delete')
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4">
                <h3 class="text-sm font-semibold text-red-800">Delete this board</h3>
                <p class="mt-1 text-sm text-red-700">
                    Every task on the board is soft-deleted with it. This cannot be undone.
                </p>
                <form method="POST" action="{{ route('boards.destroy', $board) }}" class="mt-3"
                      onsubmit="return confirm('Delete this board and all its tasks?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500">
                        Delete board
                    </button>
                </form>
            </div>
        @endcan
    </div>
</x-app-layout>
