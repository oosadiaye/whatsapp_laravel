<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-display text-xl font-semibold leading-tight text-gray-800">{{ __('Call Workspace') }}</h2>
            <div class="flex items-center gap-3">
                @if($canDial ?? false)
                    {{-- Opens the Quick Dial modal (component lives below, listens for the event). --}}
                    <button type="button" x-data @click="$dispatch('open-quick-dial')"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold shadow-sm shadow-emerald-600/20 hover:bg-emerald-700 active:scale-95 transition">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 8a2 2 0 100-4 2 2 0 000 4zM6 14a2 2 0 100-4 2 2 0 000 4zM6 20a2 2 0 100-4 2 2 0 000 4zM12 8a2 2 0 100-4 2 2 0 000 4zM12 14a2 2 0 100-4 2 2 0 000 4zM12 20a2 2 0 100-4 2 2 0 000 4zM18 8a2 2 0 100-4 2 2 0 000 4zM18 14a2 2 0 100-4 2 2 0 000 4zM18 20a2 2 0 100-4 2 2 0 000 4z"/></svg>
                        {{ __('Quick Dial') }}
                    </button>
                @endif
                <a href="{{ route('calls.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-800">{{ __('Full history →') }}</a>
            </div>
        </div>
    </x-slot>

    @php
        $dirIcon = fn (string $d) => $d === 'inbound'
            ? 'M11.25 9V5.25m0 0H7.5m3.75 0L5.25 11.25'   // arrow in
            : 'M12.75 9V5.25m0 0h3.75m-3.75 0L18.75 11.25'; // arrow out
        $aiDot = [
            \App\Models\CallLog::AI_STATUS_COMPLETED => 'bg-emerald-500',
            \App\Models\CallLog::AI_STATUS_PENDING => 'bg-amber-400 animate-pulse',
            \App\Models\CallLog::AI_STATUS_PROCESSING => 'bg-amber-400 animate-pulse',
            \App\Models\CallLog::AI_STATUS_FAILED => 'bg-red-400',
        ];
    @endphp

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- Recording status banner — honest about what's live --}}
        @unless($recordingEnabled)
            <div class="mb-4 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                <div>
                    {{ __('Call recording is off, so AI summaries won\'t generate. Set') }}
                    <code class="font-data text-xs bg-amber-100 px-1 rounded">VOICE_CALL_RECORDING_ENABLED=true</code>
                    @unless($aiConfigured) {{ __('and add a') }} <code class="font-data text-xs bg-amber-100 px-1 rounded">GEMINI_API_KEY</code> @endunless
                    {{ __('once you have a "call may be recorded" consent notice in place. Notes and history work regardless.') }}
                </div>
            </div>
        @endunless

        {{-- Quick Dial modal — places a direct AT call from the workspace.
             Agent can type a number or pick a contact from the searchable list. --}}
        @if($canDial ?? false)
            @php
                $dialKeys = [['1', ''], ['2', 'ABC'], ['3', 'DEF'], ['4', 'GHI'], ['5', 'JKL'],
                             ['6', 'MNO'], ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'], ['*', ''], ['0', '+'], ['#', '']];
            @endphp
            <div x-data="{
                open: false,
                number: '',
                contactId: null,
                calling: false,
                error: '',
                search: '',
                showContacts: false,
                selectedContactName: '',
                get displayLabel() {
                    return this.selectedContactName || this.number || '';
                },
                get filteredContacts() {
                    const all = {{ Js::from($dialContacts?->map(fn ($c) => ['id' => $c->id, 'name' => $c->name ?? $c->phone, 'phone' => $c->phone])->values() ?? []) }};
                    if (!this.search) return all;
                    const q = this.search.toLowerCase();
                    return all.filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q));
                },
                press(k) { this.number += k; this.contactId = null; this.selectedContactName = ''; },
                back() { this.number = this.number.slice(0, -1); this.contactId = null; this.selectedContactName = ''; },
                selectContact(c) {
                    this.contactId = c.id;
                    this.number = c.phone;
                    this.selectedContactName = c.name;
                    this.showContacts = false;
                    this.search = '';
                },
                async placeCall() {
                    if (this.calling || !this.number.trim()) return;
                    this.calling = true;
                    this.error = '';
                    const payload = { phone: this.number };
                    if (this.contactId) payload.contact_id = this.contactId;
                    try {
                        const res = await fetch(@js(route('calls.outbound')), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' },
                            body: JSON.stringify(payload),
                        });
                        if (res.ok) {
                            this.open = false;
                            this.number = '';
                            this.contactId = null;
                            this.selectedContactName = '';
                        } else {
                            const b = await res.json();
                            this.error = b?.error || 'Call failed.';
                        }
                    } catch {
                        this.error = 'Network error.';
                    } finally {
                        this.calling = false;
                    }
                }
            }"
                 @open-quick-dial.window="open = true; $nextTick(() => $refs.searchInput && $refs.searchInput.focus())"
                 @keydown.escape.window="open = false">
                <template x-teleport="body">
                    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
                         role="dialog" aria-modal="true" aria-label="{{ __('Quick Dial') }}">
                        <div x-show="open" x-transition.opacity
                             class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="open = false"></div>

                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="relative w-full max-w-sm bg-white rounded-2xl shadow-2xl ring-1 ring-slate-900/5 overflow-hidden">
                            <div class="flex items-center justify-between px-5 py-4 bg-slate-900 text-white">
                                <div class="flex items-center gap-2">
                                    <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M6 8a2 2 0 100-4 2 2 0 000 4zM6 14a2 2 0 100-4 2 2 0 000 4zM6 20a2 2 0 100-4 2 2 0 000 4zM12 8a2 2 0 100-4 2 2 0 000 4zM12 14a2 2 0 100-4 2 2 0 000 4zM12 20a2 2 0 100-4 2 2 0 000 4zM18 8a2 2 0 100-4 2 2 0 000 4zM18 14a2 2 0 100-4 2 2 0 000 4zM18 20a2 2 0 100-4 2 2 0 000 4z"/></svg>
                                    <h3 class="font-display text-sm font-bold uppercase tracking-wider">{{ __('Quick Dial') }}</h3>
                                </div>
                                <button type="button" @click="open = false"
                                        class="text-slate-400 hover:text-white transition" aria-label="{{ __('Close') }}">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            <div class="p-5">
                                {{-- Search input with dropdown --}}
                                <div class="relative mb-3" @click.outside="showContacts = false">
                                    <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5"
                                         :class="showContacts && 'border-emerald-400 ring-1 ring-emerald-200'">
                                        <svg class="w-4 h-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                                        <input type="text" x-model="search" x-ref="searchInput"
                                               autocomplete="off"
                                               placeholder="{{ __('Search contacts…') }}"
                                               @focus="showContacts = true"
                                               @keydown.escape="showContacts = false"
                                               @keydown.down.prevent="$focus.wrap().next($refs.contactList?.querySelector('button'))"
                                               class="flex-1 min-w-0 bg-transparent border-none p-0 text-sm text-slate-900 focus:ring-0 placeholder:text-slate-400">
                                    </div>

                                    {{-- Dropdown --}}
                                    <div x-show="showContacts" x-cloak x-ref="contactList"
                                         class="absolute z-10 mt-1 w-full max-h-52 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                        <template x-for="c in filteredContacts" :key="c.id">
                                            <button type="button" @click="selectContact(c)"
                                                    @keydown.arrow-up.prevent="$focus.wrap().prev($el)"
                                                    @keydown.arrow-down.prevent="$focus.wrap().next($el)"
                                                    class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-emerald-50 border-b border-slate-50 last:border-0 transition text-sm"
                                                    :class="number === c.phone && 'bg-emerald-50'">
                                                <span class="grid place-items-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 font-bold text-xs shrink-0" x-text="(c.name || c.phone).charAt(0).toUpperCase()"></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block font-medium text-slate-900 truncate" x-text="c.name || c.phone"></span>
                                                    <span class="block text-xs text-slate-400 font-data" x-text="c.name ? c.phone : ''"></span>
                                                </span>
                                            </button>
                                        </template>
                                        <p x-show="filteredContacts.length === 0" class="px-3 py-4 text-xs text-slate-400 text-center">{{ __('No contacts match.') }}</p>
                                    </div>
                                </div>

                                {{-- Number display --}}
                                <div class="flex items-center justify-between bg-slate-900/5 rounded-lg px-4 py-3 mb-3 min-h-[3rem]">
                                    <div class="min-w-0 flex-1 text-right">
                                        <template x-if="selectedContactName">
                                            <span class="block text-xs text-slate-500 truncate" x-text="selectedContactName"></span>
                                        </template>
                                        <span class="font-data text-2xl text-slate-900" x-text="number || '0'"></span>
                                    </div>
                                    <button type="button" @click="back()" x-show="number" x-cloak
                                            class="shrink-0 ml-2 text-slate-400 hover:text-slate-600">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83c.211-.211.498-.33.796-.33H19.5a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-9.284c-.298 0-.585-.119-.796-.33z"/></svg>
                                    </button>
                                </div>

                                {{-- Keypad --}}
                                <div class="grid grid-cols-3 gap-2 mb-4">
                                    @foreach($dialKeys as [$digit, $letters])
                                        <button type="button" @click="press('{{ $digit }}')"
                                                class="h-14 flex flex-col items-center justify-center rounded-lg bg-slate-50 hover:bg-slate-100 active:bg-slate-200 transition group">
                                            <span class="font-display text-xl leading-none text-slate-900">{{ $digit }}</span>
                                            <span class="mt-0.5 h-2 text-[8px] font-bold tracking-[0.15em] text-slate-400 group-hover:text-slate-500">{{ $letters }}</span>
                                        </button>
                                    @endforeach
                                </div>

                                {{-- Actions --}}
                                <div class="grid grid-cols-3 gap-2">
                                    <button type="button" @click="number = ''; contactId = null; selectedContactName = ''"
                                            class="py-2.5 rounded-lg bg-slate-100 text-slate-600 text-sm font-semibold hover:bg-slate-200 transition">{{ __('Clear') }}</button>
                                    <button type="button" @click="placeCall()" x-bind:disabled="!number.trim() || calling"
                                            class="col-span-2 inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition shadow-sm shadow-emerald-600/20">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                        <span x-text="calling ? '{{ __('Calling…') }}' : '{{ __('Start Call') }}'"></span>
                                    </button>
                                </div>
                                <p x-show="error" x-cloak x-text="error"
                                   class="mt-2 text-center text-xs text-red-600"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @endif

        @if(config('voice.queue_enabled'))
            <div class="mb-6 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                @livewire('call-queue', key('call-queue'))
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

            {{-- WRAP-UP — shown when a recently-ended call has no disposition.
                 x-data hides the card client-side once saved or closed, so the
                 operator isn't nagged until a full page reload. --}}
            @if($activeCall && !$activeCall->disposition && $activeCall->ended_at)
                <div class="lg:col-span-2" x-data="{ wrapUpOpen: true }"
                     x-show="wrapUpOpen"
                     @wrap-up-saved.window="wrapUpOpen = false"
                     @close-wrap-up.window="wrapUpOpen = false">
                    @livewire('call-wrap-up', ['call' => $activeCall], key('wrap-up-'.$activeCall->id))
                </div>
            @endif

            {{-- LEFT: call queue / history --}}
            <div class="lg:col-span-3 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-700">{{ __('Recent calls') }}</h3>
                    <span class="text-xs text-gray-400">{{ $calls->count() }}</span>
                </div>

                {{-- Search + filter --}}
                <form method="GET" action="{{ route('calls.workspace') }}" class="px-5 py-3 border-b border-gray-50 flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1">
                        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search calls…') }}"
                               class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:border-blue-400 focus:ring focus:ring-blue-200">
                    </div>
                    <select name="dir" class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:border-blue-400 focus:ring focus:ring-blue-200">
                        <option value="">{{ __('All directions') }}</option>
                        <option value="inbound" @selected(request('dir') === 'inbound')>{{ __('Inbound') }}</option>
                        <option value="outbound" @selected(request('dir') === 'outbound')>{{ __('Outbound') }}</option>
                    </select>
                    <select name="status" class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:border-blue-400 focus:ring focus:ring-blue-200">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="ended" @selected(request('status') === 'ended')>{{ __('Ended') }}</option>
                        <option value="missed" @selected(request('status') === 'missed')>{{ __('Missed') }}</option>
                        <option value="ringing" @selected(request('status') === 'ringing')>{{ __('Ringing') }}</option>
                    </select>
                </form>

                <div class="divide-y divide-gray-50 max-h-[70vh] overflow-y-auto">
                    @forelse($calls as $call)
                        <a href="{{ route('calls.workspace', ['call' => $call->id]) }}" wire:navigate
                           class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition {{ $call->id === $selectedCallId ? 'bg-[#25D366]/5 border-l-2 border-[#25D366]' : 'border-l-2 border-transparent' }}">
                            <span class="grid place-items-center w-9 h-9 rounded-full shrink-0 {{ $call->direction === 'inbound' ? 'bg-sky-50 text-sky-600' : 'bg-violet-50 text-violet-600' }}">
                                <span class="sr-only">{{ $call->direction === 'inbound' ? __('Inbound call') : __('Outbound call') }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $dirIcon($call->direction) }}"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-gray-900 truncate">{{ $call->contact?->name ?? $call->to_phone ?? __('Unknown') }}</span>
                                    @if($call->status === 'missed')
                                        <span class="text-[10px] font-semibold text-red-600 uppercase">{{ __('missed') }}</span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-400">{{ $call->created_at?->diffForHumans() }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if($call->notes_count > 0)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-gray-400">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h6m-6 8l-4-4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z"/></svg>
                                        {{ $call->notes_count }}
                                    </span>
                                @endif
                                @if(isset($aiDot[$call->ai_status]))
                                    <span class="w-2 h-2 rounded-full {{ $aiDot[$call->ai_status] }}" title="{{ __('AI') }}: {{ $call->ai_status }}"></span>
                                @endif
                                <span class="text-xs font-data text-gray-500">{{ sprintf('%d:%02d', intdiv((int) $call->duration_seconds, 60), (int) $call->duration_seconds % 60) }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="px-5 py-16 text-center text-sm text-gray-400">
                            {{ __('No calls yet. When a call comes in or you dial out, it lands here.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- RIGHT: per-call intelligence + notes panel --}}
            <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden h-[76vh]">
                @livewire('call-insights-panel', ['callId' => $selectedCallId], key('panel-'.($selectedCallId ?? 'none')))
            </div>
        </div>
    </div>
</x-app-layout>
