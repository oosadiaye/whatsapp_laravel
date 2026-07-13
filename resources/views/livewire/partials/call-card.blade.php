{{--
    Shared connected-call card (WhatsApp/3CX-style softphone panel).

    Rendered inside an Alpine call factory (outgoingCall / incomingAtCall) that
    provides: contactName, durationSeconds, muted, held, keypadOpen and the
    methods toggleMute / toggleHold / toggleKeypad / sendDtmf / hangup.

    $phoneExpr — the Alpine expression for the number to show (the factories use
    different property names: 'phone' inbound, 'customerPhone' outbound).
--}}
@php($phoneExpr = $phoneExpr ?? 'phone')
<div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
        <div class="flex items-center gap-2 text-gray-900">
            <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            <span class="text-sm font-semibold">{{ __('Voice call') }}</span>
        </div>
        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide"
              :class="held ? 'text-amber-600' : 'text-emerald-600'">
            <span class="w-1.5 h-1.5 rounded-full" :class="held ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'"></span>
            <span x-text="held ? '{{ __('On hold') }}' : '{{ __('Live') }}'"></span>
        </span>
    </div>

    {{-- Body --}}
    <div class="px-6 pt-6 pb-4 flex flex-col items-center">
        {{-- Avatar --}}
        <div class="relative">
            <div class="w-24 h-24 rounded-full grid place-items-center ring-4 shadow-sm"
                 :class="held ? 'bg-amber-50 ring-amber-400 text-amber-600' : 'bg-emerald-50 ring-emerald-500 text-emerald-600'">
                <svg class="w-11 h-11" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
            </div>
            <span class="absolute bottom-0 right-0 grid place-items-center w-8 h-8 rounded-full text-white ring-2 ring-white"
                  :class="held ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            </span>
        </div>

        {{-- Status --}}
        <p class="mt-4 text-xl font-bold text-gray-900 tracking-tight text-center" x-text="{{ $phoneExpr }}"></p>
        <div class="mt-1 flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full" :class="held ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'"></span>
            <span class="text-sm font-semibold" :class="held ? 'text-amber-600' : 'text-emerald-600'"
                  x-text="held ? '{{ __('On hold') }}' : '{{ __('Connected') }}'"></span>
        </div>
        <p class="mt-0.5 text-xs text-gray-500 text-center">
            {{ __('On call with') }} <span class="font-medium text-gray-700" x-text="contactName"></span>
        </p>

        {{-- Timer --}}
        <div class="mt-5 w-full rounded-xl bg-gray-50 border border-gray-100 py-4 flex items-end justify-center gap-2.5">
            <div class="flex flex-col items-center">
                <span class="text-3xl font-bold font-mono tabular-nums text-gray-900 leading-none"
                      x-text="String(Math.floor(durationSeconds / 60)).padStart(2, '0')"></span>
                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400 mt-1.5">{{ __('Min') }}</span>
            </div>
            <span class="text-3xl font-bold font-mono text-gray-300 leading-none animate-pulse">:</span>
            <div class="flex flex-col items-center">
                <span class="text-3xl font-bold font-mono tabular-nums text-gray-900 leading-none"
                      x-text="String(durationSeconds % 60).padStart(2, '0')"></span>
                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400 mt-1.5">{{ __('Sec') }}</span>
            </div>
        </div>

        {{-- DTMF keypad (toggled) --}}
        <template x-if="keypadOpen">
            <div class="mt-4 w-full grid grid-cols-3 gap-2">
                <template x-for="d in ['1','2','3','4','5','6','7','8','9','*','0','#']" :key="d">
                    <button type="button" @click="sendDtmf(d)"
                            class="h-11 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800 text-lg font-semibold font-mono active:scale-95 transition"
                            x-text="d"></button>
                </template>
            </div>
        </template>

        {{-- Action grid (Mute / Hold / Keypad) --}}
        <div class="mt-4 w-full grid grid-cols-3 gap-2.5" x-show="!keypadOpen">
            {{-- Mute --}}
            <button type="button" @click="toggleMute()"
                    class="flex flex-col items-center gap-2 rounded-xl border p-3 transition active:scale-95"
                    :class="muted ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <span class="grid place-items-center w-11 h-11 rounded-full transition-colors"
                      :class="muted ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700'">
                    <svg x-show="!muted" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                    <svg x-show="muted" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M3 3l18 18M9.75 9.348A3 3 0 019 7.5V4.5a3 3 0 015.856-.917"/></svg>
                </span>
                <span class="text-xs font-semibold text-gray-800" x-text="muted ? '{{ __('Muted') }}' : '{{ __('Mute') }}'"></span>
            </button>

            {{-- Hold --}}
            <button type="button" @click="toggleHold()"
                    class="flex flex-col items-center gap-2 rounded-xl border p-3 transition active:scale-95"
                    :class="held ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <span class="grid place-items-center w-11 h-11 rounded-full transition-colors"
                      :class="held ? 'bg-amber-500 text-white' : 'bg-amber-100 text-amber-600'">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6.75 5.25a.75.75 0 01.75.75v12a.75.75 0 01-1.5 0V6a.75.75 0 01.75-.75zm9.75.75a.75.75 0 00-1.5 0v12a.75.75 0 001.5 0V6z"/></svg>
                </span>
                <span class="text-xs font-semibold text-gray-800" x-text="held ? '{{ __('On hold') }}' : '{{ __('Hold') }}'"></span>
            </button>

            {{-- Keypad --}}
            <button type="button" @click="toggleKeypad()"
                    class="flex flex-col items-center gap-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 p-3 transition active:scale-95">
                <span class="grid place-items-center w-11 h-11 rounded-full bg-gray-100 text-gray-700">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6 6a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6 12a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM4.5 19.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM13.5 6a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM12 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM13.5 18a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM19.5 7.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM21 12a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM19.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/></svg>
                </span>
                <span class="text-xs font-semibold text-gray-800">{{ __('Keypad') }}</span>
            </button>
        </div>
    </div>

    {{-- Transfer (blind) — flag-gated. Hands the live call to another number;
         the agent's own leg drops after the server records the target. --}}
    @if(config('voice.transfer_enabled'))
        <div class="px-6 pb-1" x-show="!keypadOpen">
            <button type="button" @click="toggleTransfer()"
                    class="w-full flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 py-2.5 text-sm font-semibold text-gray-700 transition active:scale-[.99]"
                    :class="transferOpen && 'ring-2 ring-[#25D366]/40'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                {{ __('Transfer') }}
            </button>
            <div x-show="transferOpen" x-cloak class="mt-2 rounded-xl border border-gray-200 bg-gray-50 p-3">
                <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1.5">{{ __('Transfer to number') }}</label>
                <div class="flex gap-2">
                    <input type="tel" x-model="transferNumber" placeholder="+2348000000000"
                           class="flex-1 rounded-lg border-gray-300 text-sm focus:border-[#25D366] focus:ring-[#25D366]">
                    <button type="button" @click="transferToNumber()" :disabled="transferBusy"
                            class="px-4 rounded-lg bg-[#25D366] text-white text-sm font-semibold hover:bg-[#1da851] disabled:opacity-50">
                        <span x-show="!transferBusy">{{ __('Send') }}</span>
                        <span x-show="transferBusy" x-cloak>…</span>
                    </button>
                </div>
                <p class="mt-1.5 text-[11px] text-gray-400">{{ __('The call moves to this number and your line drops.') }}</p>
            </div>
        </div>
    @endif

    {{-- Drop --}}
    <div class="px-6 pb-6 pt-1">
        <button type="button" @click="hangup()"
                class="w-full h-14 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold uppercase tracking-wide flex items-center justify-center gap-2 shadow-lg shadow-red-500/20 active:scale-[.98] transition">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08a.956.956 0 01-.29-.7c0-.28.11-.53.29-.71C3.34 8.77 7.46 7 12 7s8.66 1.77 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28a11.27 11.27 0 00-2.67-1.85.996.996 0 01-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
            {{ __('Drop') }}
        </button>
    </div>
</div>
