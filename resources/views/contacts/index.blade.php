<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Contacts') }}
            </h2>
            <a href="{{ route('contacts.import') }}"
               class="inline-flex items-center px-4 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 transition ease-in-out duration-150">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                {{ __('Import Contacts') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Search --}}
            <div class="mb-6">
                <form method="GET" action="{{ route('contacts.index') }}" class="flex gap-3">
                    <input type="text"
                           name="search"
                           value="{{ request('search') }}"
                           placeholder="Search by name or phone..."
                           class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366] sm:text-sm">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-[#25D366] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#1da851] transition ease-in-out duration-150">
                        {{ __('Search') }}
                    </button>
                    @if(request('search'))
                        <a href="{{ route('contacts.index') }}"
                           class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 transition ease-in-out duration-150">
                            {{ __('Clear') }}
                        </a>
                    @endif
                </form>
            </div>

            {{-- Table --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    {{ __('Name') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    {{ __('Phone') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    {{ __('Groups') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    {{ __('Active') }}
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    {{ __('Actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($contacts as $contact)
                                <tr class="hover:bg-gray-50 transition-colors duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $contact->name ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">
                                        {{ $contact->phone }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        @if($contact->groups && $contact->groups->count())
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($contact->groups as $group)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                        {{ $group->name }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-gray-400">{{ __('None') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($contact->is_active)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ __('Active') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                {{ __('Inactive') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <div class="flex items-center justify-end gap-2"
                                             x-data="{
                                                 openCallConfirm: false,
                                                 openInstancePicker: false,
                                                 action: null,
                                                 instanceId: null,
                                                 submit() {
                                                     const form = document.getElementById('contact-action-form-{{ $contact->id }}');
                                                     form.action = this.action;
                                                     if (this.instanceId) {
                                                         let hidden = form.querySelector('[name=instance_id]');
                                                         if (!hidden) {
                                                             hidden = document.createElement('input');
                                                             hidden.type = 'hidden';
                                                             hidden.name = 'instance_id';
                                                             form.appendChild(hidden);
                                                         }
                                                         hidden.value = this.instanceId;
                                                     }
                                                     form.submit();
                                                 }
                                             }">

                                            {{-- Hidden form, submitted by Alpine after picker/confirm flows resolve. --}}
                                            <form id="contact-action-form-{{ $contact->id }}" method="POST" action="" class="hidden">
                                                @csrf
                                            </form>

                                            {{-- CHAT button — always shown for users with conversations.reply --}}
                                            @can('conversations.reply')
                                                @if($needsInstancePicker)
                                                    <button type="button"
                                                            @click="action = '{{ route('contacts.startChat', $contact) }}'; openInstancePicker = true"
                                                            class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                                                            title="Chat with {{ $contact->name ?? $contact->phone }}">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                                        </svg>
                                                    </button>
                                                @else
                                                    {{-- Single-instance fast path: no picker, just submit. --}}
                                                    <form method="POST" action="{{ route('contacts.startChat', $contact) }}" class="inline">
                                                        @csrf
                                                        <button type="submit"
                                                                class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                                                                title="Chat with {{ $contact->name ?? $contact->phone }}">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                @endif
                                            @endcan

                                            {{-- CALL button — gated by permission AND engagement --}}
                                            @can('conversations.call')
                                                @if($contact->is_engaged ?? false)
                                                    <button type="button"
                                                            @click="action = '{{ route('contacts.startCall', $contact) }}'; @if($needsInstancePicker) openInstancePicker = true @else openCallConfirm = true @endif"
                                                            class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-600 text-white hover:bg-emerald-700 transition"
                                                            title="Call {{ $contact->name ?? $contact->phone }}">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                                        </svg>
                                                    </button>
                                                @else
                                                    {{-- Disabled state with policy tooltip --}}
                                                    <button type="button" disabled
                                                            class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-gray-100 text-gray-400 cursor-not-allowed"
                                                            title="Recipient must message you first (Meta opt-in policy)">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                                        </svg>
                                                    </button>
                                                @endif
                                            @endcan

                                            <a href="{{ route('contacts.edit', $contact) }}" class="text-[#25D366] hover:text-[#1da851] font-medium">{{ __('Edit') }}</a>
                                            <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this contact?') }}')" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('Delete') }}</button>
                                            </form>

                                            {{-- INSTANCE PICKER MODAL — only renders for multi-instance users --}}
                                            @if($needsInstancePicker)
                                                <template x-teleport="body">
                                                    <div x-show="openInstancePicker" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="openInstancePicker = false">
                                                        <div class="absolute inset-0 bg-black/50"></div>
                                                        <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                                                            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Pick a WhatsApp number') }}</h3>
                                                            <p class="text-sm text-gray-500 mb-4">{{ __('Which of your numbers should this conversation use?') }}</p>
                                                            <select x-model="instanceId" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                                                <option value="">{{ __('-- Select --') }}</option>
                                                                @foreach($activeInstances as $inst)
                                                                    <option value="{{ $inst->id }}">{{ $inst->display_name ?? $inst->instance_name }} · {{ $inst->business_phone_number }}</option>
                                                                @endforeach
                                                            </select>
                                                            <div class="flex justify-end gap-2 mt-5">
                                                                <button type="button" @click="openInstancePicker = false" class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Cancel') }}</button>
                                                                <button type="button"
                                                                        @click="if (!instanceId) return; openInstancePicker = false; if (action.includes('/call')) { openCallConfirm = true } else { submit() }"
                                                                        class="px-5 py-2 text-sm text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
                                                                    {{ __('Continue') }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            @endif

                                            {{-- CALL CONFIRMATION MODAL — same pattern as Voice Phase A's chat-header call button --}}
                                            <template x-teleport="body">
                                                <div x-show="openCallConfirm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="openCallConfirm = false">
                                                    <div class="absolute inset-0 bg-black/50"></div>
                                                    <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                                                        <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Call') }} {{ $contact->name ?? $contact->phone }}?</h3>
                                                        <dl class="text-sm space-y-1 mb-4">
                                                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Number') }}:</dt><dd class="text-gray-900 font-mono">{{ $contact->phone }}</dd></div>
                                                        </dl>
                                                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 mb-4">
                                                            {{ __('Counts toward your daily Meta call quota. Audio rings on the device where this WhatsApp Business number is registered.') }}
                                                        </p>
                                                        <div class="flex justify-end gap-2">
                                                            <button type="button" @click="openCallConfirm = false" class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Cancel') }}</button>
                                                            <button type="button" @click="openCallConfirm = false; submit()" class="px-5 py-2 text-sm text-white bg-emerald-600 rounded-md hover:bg-emerald-700">{{ __('Call now') }}</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">
                                        <div class="flex flex-col items-center gap-2">
                                            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            </svg>
                                            {{ __('No contacts found.') }}
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($contacts->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $contacts->withQueryString()->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
