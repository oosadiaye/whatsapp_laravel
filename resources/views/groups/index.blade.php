<x-app-layout>
    <x-slot name="header">
        <div x-data class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Contact Groups') }}
            </h2>
            @can('groups.create')
                <button @click="$dispatch('open-create-group')"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-white rounded-lg shadow-sm hover:opacity-90 transition"
                        style="background-color: #25D366;">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Create Group
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    {{ session('error') }}
                </div>
            @endif

            @if ($groups->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="text-center py-12 px-6">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <h3 class="mt-4 text-sm font-medium text-gray-900">No groups yet</h3>
                        <p class="mt-1 text-sm text-gray-500">Create a group to organize your contacts for blast messaging.</p>
                        @can('groups.create')
                            <div x-data class="mt-6">
                                <button @click="$dispatch('open-create-group')"
                                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-white rounded-lg hover:opacity-90 transition"
                                        style="background-color: #25D366;">
                                    Create Your First Group
                                </button>
                            </div>
                        @endcan
                    </div>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach ($groups as $group)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition flex flex-col">
                            <div class="p-6 flex-1">
                                <div class="flex items-start justify-between">
                                    <h3 class="text-base font-semibold text-gray-900 truncate">{{ $group->name }}</h3>
                                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 shrink-0">
                                        {{ $group->contacts_count ?? $group->contacts->count() }} contacts
                                    </span>
                                </div>
                                @if ($group->description)
                                    <p class="mt-2 text-sm text-gray-500 line-clamp-2">{{ $group->description }}</p>
                                @else
                                    <p class="mt-2 text-sm text-gray-400 italic">No description</p>
                                @endif
                            </div>
                            <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-2">
                                <a href="{{ route('groups.show', $group) }}"
                                   class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                                    View
                                </a>
                                <form action="{{ route('groups.destroy', $group) }}" method="POST" class="inline"
                                      onsubmit="return confirm('Delete this group? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 rounded-md hover:bg-red-100 transition">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Create Group Modal — only rendered if the user can actually create.
         Saves a few KB of HTML and prevents the modal's internal text from
         leaking through to permission-denied users. --}}
    @can('groups.create')
    <div x-data="{ open: false }"
         @open-create-group.window="open = true"
         x-show="open"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto">

        {{-- Backdrop --}}
        <div x-show="open"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-gray-500/75"
             @click="open = false"></div>

        {{-- Modal --}}
        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="open"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 @click.away="open = false"
                 class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6">

                <h3 class="text-lg font-semibold text-gray-900 mb-4">Create New Group</h3>

                <form method="POST" action="{{ route('groups.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="group_name" :value="__('Group Name')" />
                        <x-text-input id="group_name"
                                      name="name"
                                      type="text"
                                      class="mt-1 block w-full"
                                      required
                                      placeholder="e.g. VIP Customers" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="group_description" :value="__('Description')" />
                        <textarea id="group_description"
                                  name="description"
                                  rows="3"
                                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                  placeholder="Optional description for this group..."></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <button type="button"
                                @click="open = false"
                                class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        <button type="submit"
                                class="inline-flex items-center px-5 py-2 text-sm font-medium text-white rounded-lg shadow-sm hover:opacity-90 transition"
                                style="background-color: #25D366;">
                            Create Group
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan
</x-app-layout>
