<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-display text-xl font-semibold leading-tight text-gray-800">{{ __('Call Workspace') }}</h2>
            <a href="{{ route('calls.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-800">{{ __('Full history →') }}</a>
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

        {{-- Dial pad (calls.dial) — enter a number or pick a contact, then call.
             Resolves the destination server-side and opens the conversation view,
             which places the call through the browser softphone. --}}
        @if($canDial ?? false)
            <div class="mb-6 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden"
                 x-data="{ number: '', press(k) { this.number += k }, back() { this.number = this.number.slice(0, -1) } }">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    <h3 class="text-sm font-bold text-gray-700">{{ __('Dial a number') }}</h3>
                </div>
                <form method="POST" action="{{ route('calls.dial') }}" class="p-5 sm:flex sm:items-start sm:gap-8">
                    @csrf
                    <div class="sm:w-60">
                        <label for="bq-dial-number" class="sr-only">{{ __('Phone number or contact') }}</label>
                        <input type="text" id="bq-dial-number" name="phone" x-model="number" list="bq-dial-contacts"
                               inputmode="tel" autocomplete="off" placeholder="{{ __('+234… or a contact name') }}"
                               class="w-full text-lg font-data tracking-wide rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        <datalist id="bq-dial-contacts">
                            @foreach($dialContacts ?? [] as $c)
                                <option value="{{ $c->phone }}">{{ $c->name }}</option>
                            @endforeach
                        </datalist>

                        <div class="mt-3 grid grid-cols-3 gap-2 max-w-[220px]">
                            @foreach(['1','2','3','4','5','6','7','8','9','*','0','#'] as $k)
                                <button type="button" @click="press('{{ $k }}')"
                                        class="py-2.5 rounded-lg bg-gray-50 hover:bg-gray-100 active:bg-gray-200 text-gray-800 text-lg font-semibold transition">{{ $k }}</button>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2 max-w-[220px]">
                            <button type="button" @click="back()" class="flex-1 py-1.5 text-xs rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-500">&#9003; {{ __('Delete') }}</button>
                            <button type="button" @click="number = ''" class="flex-1 py-1.5 text-xs rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-500">{{ __('Clear') }}</button>
                        </div>
                    </div>

                    <div class="mt-5 sm:mt-0 sm:flex-1">
                        <p class="text-xs text-gray-500 mb-4 leading-relaxed">{{ __('Type a number, or start typing a contact name to pick one. Calling opens the conversation view, where the customer\'s phone rings and you talk through your browser.') }}</p>
                        <button type="submit" x-bind:disabled="! number.trim()"
                                class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                            {{ __('Call') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

            {{-- LEFT: call queue / history --}}
            <div class="lg:col-span-3 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-700">{{ __('Recent calls') }}</h3>
                    <span class="text-xs text-gray-400">{{ $calls->count() }}</span>
                </div>

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
