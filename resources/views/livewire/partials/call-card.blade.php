{{--
    Shared connected-call panel — a 3CX-style floating softphone.

    Rendered inside an Alpine call factory (outgoingCall / incomingAtCall) that
    provides: contactName, durationSeconds, muted, held, keypadOpen, recording,
    recordingAvailable and the methods toggleMute / toggleHold / toggleKeypad /
    toggleRecording / sendDtmf / hangup (transfer* when the flag is on).

    $phoneExpr — the Alpine expression for the number to show (the factories use
    different property names: 'phone' inbound, 'customerPhone' outbound).
--}}
@php($phoneExpr = $phoneExpr ?? 'phone')
<div class="fixed bottom-5 right-5 z-50 w-[380px] max-w-[calc(100vw-2.5rem)] max-h-[calc(100vh-2.5rem)] overflow-y-auto bg-white rounded-3xl shadow-2xl ring-1 ring-black/5">
    {{-- Header --}}
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div class="flex items-center gap-2.5">
            <span class="grid place-items-center w-8 h-8 rounded-lg bg-emerald-600 text-white shadow-sm shadow-emerald-500/30">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            </span>
            <span class="text-[15px] font-bold text-gray-900 font-display">{{ __('Voice Call') }}</span>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"
              :class="held ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">
            <span class="w-1.5 h-1.5 rounded-full" :class="held ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'"></span>
            <span x-text="held ? '{{ __('On hold') }}' : '{{ __('Live') }}'"></span>
        </span>
    </div>

    {{-- Body --}}
    <div class="px-6 pt-6 pb-5 flex flex-col items-center">
        {{-- Avatar — rounded-square tile with the contact's initial + call badge --}}
        <div class="relative">
            <div class="w-[84px] h-[84px] rounded-2xl grid place-items-center ring-4 shadow-sm text-3xl font-bold uppercase select-none font-display"
                 :class="held ? 'bg-amber-50 ring-amber-400 text-amber-600' : 'bg-emerald-50 ring-emerald-500 text-emerald-600'"
                 x-text="((contactName || '').trim().charAt(0) || '#')"></div>
            <span class="absolute -bottom-1.5 -right-1.5 grid place-items-center w-8 h-8 rounded-xl text-white ring-2 ring-white shadow"
                  :class="held ? 'bg-amber-500' : 'bg-emerald-500'">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            </span>
        </div>

        {{-- Number + status --}}
        <p class="mt-4 text-[22px] font-bold text-gray-900 tracking-tight text-center font-data" x-text="{{ $phoneExpr }}"></p>
        <div class="mt-1 flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full" :class="held ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'"></span>
            <span class="text-sm font-semibold" :class="held ? 'text-amber-600' : 'text-emerald-600'"
                  x-text="held ? '{{ __('On hold') }}' : '{{ __('Connected') }}'"></span>
        </div>
        <p class="mt-1 text-xs text-gray-500 text-center">
            {{ __('On call with') }} <span class="font-medium text-gray-700" x-text="contactName"></span>
        </p>

        {{-- Timer --}}
        <div class="mt-5 w-full rounded-2xl bg-gray-50 border border-gray-100 py-5 flex items-end justify-center gap-3">
            <div class="flex flex-col items-center">
                <span class="text-[40px] font-bold font-data tabular-nums text-gray-900 leading-none"
                      x-text="String(Math.floor(durationSeconds / 60)).padStart(2, '0')"></span>
                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400 mt-2">{{ __('Minutes') }}</span>
            </div>
            <span class="text-[40px] font-bold font-data text-gray-300 leading-none">:</span>
            <div class="flex flex-col items-center">
                <span class="text-[40px] font-bold font-data tabular-nums text-gray-900 leading-none"
                      x-text="String(durationSeconds % 60).padStart(2, '0')"></span>
                <span class="text-[10px] uppercase font-bold tracking-widest text-gray-400 mt-2">{{ __('Seconds') }}</span>
            </div>
        </div>

        {{-- DTMF keypad — appears above the grid when open; the Keypad card below
             toggles it (highlighted while open), so it's always dismissible. --}}
        <template x-if="keypadOpen">
            <div class="mt-4 w-full grid grid-cols-3 gap-2">
                <template x-for="d in ['1','2','3','4','5','6','7','8','9','*','0','#']" :key="d">
                    <button type="button" @click="sendDtmf(d)"
                            class="h-12 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 text-lg font-semibold font-data active:scale-95 transition"
                            x-text="d"></button>
                </template>
            </div>
        </template>

        {{-- 2×2 control grid: Mute / Hold / Keypad / Record --}}
        <div class="mt-4 w-full grid grid-cols-2 gap-3">
            {{-- Mute --}}
            <button type="button" @click="toggleMute()"
                    class="flex flex-col items-center justify-center gap-2 rounded-2xl border p-4 transition active:scale-[.97]"
                    :class="muted ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <span class="grid place-items-center w-12 h-12 rounded-xl transition-colors"
                      :class="muted ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700'">
                    <svg x-show="!muted" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                    <svg x-show="muted" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M3 3l18 18M9.75 9.348A3 3 0 019 7.5V4.5a3 3 0 015.856-.917"/></svg>
                </span>
                <span class="text-sm font-bold text-gray-900">{{ __('Mute') }}</span>
                <span class="text-[11px]" :class="muted ? 'text-red-500 font-semibold' : 'text-gray-400'"
                      x-text="muted ? '{{ __('Mic off') }}' : '{{ __('Mic active') }}'"></span>
            </button>

            {{-- Hold --}}
            <button type="button" @click="toggleHold()"
                    class="flex flex-col items-center justify-center gap-2 rounded-2xl border p-4 transition active:scale-[.97]"
                    :class="held ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <span class="grid place-items-center w-12 h-12 rounded-xl transition-colors"
                      :class="held ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-500'">
                    <svg x-show="!held" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6.75 5.25a.75.75 0 01.75.75v12a.75.75 0 01-1.5 0V6a.75.75 0 01.75-.75zm9.75.75a.75.75 0 00-1.5 0v12a.75.75 0 001.5 0V6z"/></svg>
                    <svg x-show="held" x-cloak class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg>
                </span>
                <span class="text-sm font-bold text-gray-900" x-text="held ? '{{ __('Resume') }}' : '{{ __('Hold') }}'"></span>
                <span class="text-[11px]" :class="held ? 'text-amber-600 font-semibold' : 'text-gray-400'"
                      x-text="held ? '{{ __('On hold') }}' : '{{ __('Call active') }}'"></span>
            </button>

            {{-- Keypad --}}
            <button type="button" @click="toggleKeypad()"
                    class="flex flex-col items-center justify-center gap-2 rounded-2xl border p-4 transition active:scale-[.97]"
                    :class="keypadOpen ? 'border-gray-300 bg-gray-50 ring-2 ring-gray-200' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <span class="grid place-items-center w-12 h-12 rounded-xl transition-colors"
                      :class="keypadOpen ? 'bg-gray-700 text-white' : 'bg-gray-100 text-gray-700'">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6 6a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6 12a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM4.5 19.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM13.5 6a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM12 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM13.5 18a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM19.5 7.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM21 12a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM19.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/></svg>
                </span>
                <span class="text-sm font-bold text-gray-900">{{ __('Keypad') }}</span>
                <span class="text-[11px] text-gray-400">{{ __('DTMF tones') }}</span>
            </button>

            {{-- Record --}}
            <button type="button" @click="toggleRecording()" :disabled="!recordingAvailable"
                    class="flex flex-col items-center justify-center gap-2 rounded-2xl border p-4 transition active:scale-[.97]"
                    :class="!recordingAvailable ? 'border-gray-200 bg-white opacity-60 cursor-not-allowed' : (recording ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white hover:bg-gray-50')"
                    :title="recordingAvailable ? '' : '{{ __('Enable call recording in Settings → Voice') }}'">
                <span class="grid place-items-center w-12 h-12 rounded-xl transition-colors"
                      :class="recording ? 'bg-red-500 text-white' : 'bg-red-50 text-red-500'">
                    <svg class="w-5 h-5" :class="recording && 'animate-pulse'" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/></svg>
                </span>
                <span class="text-sm font-bold text-gray-900">{{ __('Record') }}</span>
                <span class="text-[11px]" :class="recording ? 'text-red-500 font-semibold' : 'text-gray-400'"
                      x-text="recording ? '{{ __('Recording') }}' : '{{ __('Off') }}'"></span>
            </button>
        </div>
    </div>

    {{-- Transfer (blind) — flag-gated. Hands the live call to another number;
         the agent's own leg drops after the server records the target. --}}
    @if(config('voice.transfer_enabled'))
        <div class="px-6 pb-1">
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
                class="w-full h-14 rounded-2xl bg-red-600 hover:bg-red-700 text-white font-bold uppercase tracking-widest text-sm flex items-center justify-center gap-2.5 shadow-lg shadow-red-500/25 active:scale-[.98] transition">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08a.956.956 0 01-.29-.7c0-.28.11-.53.29-.71C3.34 8.77 7.46 7 12 7s8.66 1.77 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28a11.27 11.27 0 00-2.67-1.85.996.996 0 01-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
            {{ __('Drop') }}
        </button>
    </div>
</div>
