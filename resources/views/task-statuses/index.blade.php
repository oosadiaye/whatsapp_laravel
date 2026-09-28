<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Task statuses</h2>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto sm:px-6 lg:px-8">

        <p class="mb-6 text-sm text-gray-500">
            These are the columns every task board uses. Add a stage, rename one, or change
            the order and cards move with it. A column's
            <span class="font-medium text-gray-700">key</span> is stored on every card in that
            column, so it can only be edited while the column is empty.
        </p>

        <p class="mb-6 rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            A <span class="font-medium text-gray-700">WIP limit</span> is how many cards a column
            should aim to hold. It shows as a count on the column header and turns amber when the
            column is over.
            <span class="font-medium text-gray-700">It never blocks a move</span> — a limit that
            refuses work punishes the person reporting that the column is full, and sends the team
            around the board instead of through it.
        </p>

        {{-- A <form> cannot legally wrap <td> elements, so every form is declared
             here and the inputs/buttons reference it via the HTML5 form attribute. --}}
        @foreach($statuses as $status)
            <form method="POST" action="{{ route('task-statuses.update', $status) }}"
                  id="status-form-{{ $status->id }}" class="hidden">
                @csrf
                @method('PUT')
            </form>
            <form method="POST" action="{{ route('task-statuses.destroy', $status) }}"
                  id="status-delete-{{ $status->id }}" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <div class="mb-8 overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Key</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Colour</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cards</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            WIP limit
                            <span class="block normal-case tracking-normal text-gray-400">advisory</span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Flags</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse($statuses as $status)
                        @php $locked = $status->tasks_count > 0; @endphp

                        <tr>
                            <td class="px-4 py-3">
                                <input type="text" name="name" form="status-form-{{ $status->id }}"
                                       value="{{ old('name', $status->name) }}" required maxlength="100"
                                       class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </td>

                            <td class="px-4 py-3">
                                <input type="text" name="slug" form="status-form-{{ $status->id }}"
                                       value="{{ old('slug', $status->slug) }}" maxlength="100"
                                       @disabled($locked)
                                       class="w-full rounded-md border-gray-300 font-mono text-xs shadow-sm disabled:bg-gray-100 disabled:text-gray-400">
                                @if($locked)
                                    <p class="mt-1 text-[11px] text-gray-400">Locked while cards use it</p>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <select name="color" form="status-form-{{ $status->id }}"
                                        class="rounded-md border-gray-300 text-sm shadow-sm">
                                    @foreach(\App\Models\TaskStatus::COLORS as $color)
                                        <option value="{{ $color }}" @selected(old('color', $status->color) === $color)>
                                            {{ ucfirst($color) }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="px-4 py-3">
                                <input type="number" name="position" form="status-form-{{ $status->id }}" min="0"
                                       value="{{ old('position', $status->position) }}"
                                       class="w-20 rounded-md border-gray-300 text-sm shadow-sm">
                            </td>

                            <td class="px-4 py-3 text-sm text-gray-600">{{ $status->tasks_count }}</td>

                            <td class="px-4 py-3">
                                <input type="number" name="wip_limit" form="status-form-{{ $status->id }}"
                                       min="1" max="999" placeholder="—"
                                       value="{{ old('wip_limit', $status->wip_limit) }}"
                                       @disabled($status->is_completed)
                                       title="{{ $status->is_completed
                                           ? 'A finished column cannot have a limit'
                                           : 'Cards this column should aim to hold. Advisory — moves are never blocked.' }}"
                                       class="w-20 rounded-md border-gray-300 text-sm shadow-sm disabled:bg-gray-100 disabled:text-gray-400">
                                @error('wip_limit')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </td>

                            <td class="px-4 py-3 text-xs text-gray-600">
                                <label class="flex items-center gap-1.5">
                                    <input type="checkbox" name="is_default" value="1" form="status-form-{{ $status->id }}"
                                           @checked(old('is_default', $status->is_default))>
                                    Default
                                </label>
                                <label class="mt-1 flex items-center gap-1.5">
                                    <input type="checkbox" name="is_completed" value="1" form="status-form-{{ $status->id }}"
                                           @checked(old('is_completed', $status->is_completed))>
                                    Completed
                                </label>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" form="status-form-{{ $status->id }}"
                                            class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
                                        Save
                                    </button>
                                    <button type="submit" form="status-delete-{{ $status->id }}"
                                            @disabled($status->is_default || $locked)
                                            title="{{ $status->is_default
                                                ? 'Promote another column to default first'
                                                : ($locked ? 'Move the cards out of this column first' : 'Delete this status') }}"
                                            class="rounded-md px-3 py-1.5 text-xs font-medium
                                                   {{ ($status->is_default || $locked)
                                                      ? 'cursor-not-allowed text-gray-300'
                                                      : 'text-red-600 hover:text-red-800' }}">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">
                                No statuses defined yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-semibold text-gray-800">Add a status</h3>

            <form method="POST" action="{{ route('task-statuses.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf

                <div>
                    <label for="new-name" class="mb-1 block text-xs font-medium text-gray-600">Name</label>
                    <input id="new-name" type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                           placeholder="Blocked"
                           class="rounded-md border-gray-300 text-sm shadow-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="new-slug" class="mb-1 block text-xs font-medium text-gray-600">
                        Key <span class="text-gray-400">(optional)</span>
                    </label>
                    <input id="new-slug" type="text" name="slug" value="{{ old('slug') }}" maxlength="100"
                           placeholder="auto from name"
                           class="rounded-md border-gray-300 font-mono text-xs shadow-sm">
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="new-color" class="mb-1 block text-xs font-medium text-gray-600">Colour</label>
                    <select id="new-color" name="color" class="rounded-md border-gray-300 text-sm shadow-sm">
                        @foreach(\App\Models\TaskStatus::COLORS as $color)
                            <option value="{{ $color }}" @selected(old('color') === $color)>{{ ucfirst($color) }}</option>
                        @endforeach
                    </select>
                    @error('color') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-1.5 pb-2 text-sm text-gray-700">
                    <input type="checkbox" name="is_completed" value="1" @checked(old('is_completed'))>
                    Completed
                </label>

                <div>
                    <label for="new-wip" class="mb-1 block text-xs font-medium text-gray-600">
                        WIP limit <span class="text-gray-400">(optional)</span>
                    </label>
                    <input id="new-wip" type="number" name="wip_limit" value="{{ old('wip_limit') }}"
                           min="1" max="999" placeholder="none"
                           title="Cards this column should aim to hold. Advisory — moves are never blocked."
                           class="w-24 rounded-md border-gray-300 text-sm shadow-sm">
                    @error('wip_limit') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Add status
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
