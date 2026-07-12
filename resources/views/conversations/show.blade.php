<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('conversations.index') }}" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div>
                    <h2 class="font-semibold text-lg text-gray-800 leading-tight">
                        {{ $conversation->contact->display_name }}
                    </h2>
                    <p class="text-xs text-gray-500 flex items-center gap-2">
                        <span>{{ $conversation->contact->phone }} · via {{ $conversation->whatsappInstance->instance_name }}</span>

                        @can('conversations.assign')
                            {{-- Assignment dropdown for managers/admins --}}
                            <span class="inline-flex items-center gap-1" x-data="{ open: false }">
                                ·
                                <button type="button"
                                        @click="open = !open"
                                        @click.outside="open = false"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded {{ $conversation->assignedTo ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }} hover:opacity-80">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    {{ $conversation->assignedTo ? $conversation->assignedTo->name : __('Unassigned') }}
                                </button>

                                <div x-show="open" x-cloak x-transition
                                     class="absolute mt-6 right-0 z-30 bg-white border border-gray-200 rounded-lg shadow-lg p-2 w-64 max-h-80 overflow-y-auto">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 px-2 mb-1">{{ __('Assign to') }}</p>

                                    {{-- Self-assign quick action --}}
                                    @if($conversation->assigned_to_user_id !== auth()->id())
                                        <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ auth()->id() }}">
                                            <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 text-sm font-medium text-[#25D366]">
                                                {{ __('Take this conversation (assign to me)') }}
                                            </button>
                                        </form>
                                        <hr class="my-1">
                                    @endif

                                    {{-- Other staff --}}
                                    @foreach($assignableStaff as $staff)
                                        @if($staff->id !== auth()->id() && $staff->id !== $conversation->assigned_to_user_id)
                                            <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                                @csrf
                                                <input type="hidden" name="user_id" value="{{ $staff->id }}">
                                                <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 text-sm text-gray-800">
                                                    {{ $staff->name }} <span class="text-xs text-gray-400">· {{ $staff->email }}</span>
                                                </button>
                                            </form>
                                        @endif
                                    @endforeach

                                    @if($conversation->assignedTo)
                                        <hr class="my-1">
                                        <form method="POST" action="{{ route('conversations.assign', $conversation) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="">
                                            <button type="submit" class="w-full text-left px-2 py-1.5 rounded hover:bg-red-50 text-sm text-red-600">
                                                {{ __('Unassign') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </span>
                        @else
                            @if($conversation->assignedTo)
                                · {{ __('Assigned to') }} {{ $conversation->assignedTo->name }}
                            @endif
                        @endcan
                    </p>
                </div>
            </div>

            @can('conversations.call')
                <div x-data="{
                    open: false,
                    placing: false,
                    error: '',
                    async placeCall() {
                        if (this.placing) return;
                        this.placing = true;
                        this.error = '';
                        try {
                            const res = await fetch(@js(route('calls.outbound')), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': @js(csrf_token()),
                                    'Accept': 'application/json',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({ conversation_id: {{ $conversation->id }} }),
                            });
                            if (res.ok) {
                                // The in-flight-call banner (polls every 3s) mounts the
                                // softphone; it auto-answers when the customer picks up.
                                this.open = false;
                                return;
                            }
                            let msg = 'Could not start the call.';
                            try { const b = await res.json(); if (b && b.error) msg = b.error; } catch (e) {}
                            this.error = msg;
                        } catch (e) {
                            this.error = 'Network error placing the call. Check your connection and try again.';
                        } finally {
                            this.placing = false;
                        }
                    }
                }">
                    <button type="button"
                            @click="open = true"
                            class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition"
                            title="Call {{ $conversation->contact->name }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                        </svg>
                    </button>

                    <template x-teleport="body">
                        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                             @click.self="open = false">
                            <div class="absolute inset-0 bg-black/50"></div>

                            <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">Call {{ $conversation->contact->name }}?</h3>
                                <dl class="text-sm space-y-1 mb-4">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Number:</dt>
                                        <dd class="text-gray-900 font-mono">{{ $conversation->contact->phone }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">From:</dt>
                                        <dd class="text-gray-900">{{ $conversation->whatsappInstance->display_name ?? $conversation->whatsappInstance->instance_name }}</dd>
                                    </div>
                                </dl>
                                <p class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded p-2 mb-4">
                                    This will dial the customer's phone number directly via Africa's Talking. Standard per-minute rates apply. Audio plays in your browser.
                                </p>
                                <p x-show="error" x-cloak x-text="error"
                                   class="text-xs text-red-700 bg-red-50 border border-red-200 rounded p-2 mb-4"></p>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="open = false"
                                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        Cancel
                                    </button>
                                    <button type="button" @click="placeCall()" :disabled="placing"
                                            class="inline-flex items-center px-5 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                        </svg>
                                        <span x-text="placing ? 'Starting…' : 'Call now'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            @endcan

            {{-- 24h window indicator --}}
            <div class="text-right">
                @if($conversation->isWindowOpen())
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Reply window open') }} · {{ $conversation->windowHoursLeft() }}h left
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                        <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Window expired — template required') }}
                    </span>
                @endif
            </div>
        </div>
    </x-slot>

    @livewire('in-flight-call', ['conversationId' => $conversation->id])

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- Flash messages --}}
            @if(session('error'))
                <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            {{-- Chat thread — live-updating via Livewire poll (inbound replies
                 appear without a manual refresh). --}}
            @livewire('conversation-thread', ['conversationId' => $conversation->id])

            {{-- Reply form --}}
            @can('conversations.reply')
                <div class="bg-white rounded-xl shadow-sm p-4">
                    @if($conversation->isWindowOpen())
                        {{-- Freeform reply (within 24h window) --}}
                        <form method="POST" action="{{ route('conversations.reply', $conversation) }}"
                              enctype="multipart/form-data" class="space-y-3"
                              x-data="{ fileName: '' }">
                            @csrf
                            <textarea name="body" rows="3"
                                      placeholder="{{ __('Type your reply, or attach a file…') }}"
                                      class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"></textarea>
                            <div class="flex items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer hover:text-gray-900">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span x-text="fileName || '{{ __('Attach') }}'" class="truncate max-w-[16rem]"></span>
                                    <input type="file" name="media" class="hidden"
                                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                                           x-on:change="fileName = $event.target.files[0]?.name || ''">
                                </label>
                                <button type="submit"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-lg hover:bg-[#1da851]">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                    {{ __('Send') }}
                                </button>
                            </div>
                        </form>
                    @else
                        {{-- Template-only mode (window expired) --}}
                        <div class="space-y-3">
                            <p class="text-sm text-gray-600">
                                {{ __('Window expired. Send an approved template to re-open the conversation:') }}
                            </p>

                            @if($templates->isEmpty())
                                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
                                    {{ __('No approved templates available. Sync templates from the Templates page first.') }}
                                </p>
                            @else
                                <form method="POST" action="{{ route('conversations.reply', $conversation) }}" class="space-y-3">
                                    @csrf
                                    <select name="message_template_id" required
                                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                        <option value="">{{ __('Pick a template...') }}</option>
                                        @foreach($templates as $template)
                                            <option value="{{ $template->id }}">{{ $template->name }} · {{ $template->language }}</option>
                                        @endforeach
                                    </select>
                                    <div class="flex justify-end">
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 px-4 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-lg hover:bg-[#1da851]">
                                            {{ __('Send Template') }}
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
