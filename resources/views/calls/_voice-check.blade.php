{{-- "Check my voice setup": tests, from THIS browser and network, the things that decide
     whether a call can be heard — microphone, softphone registration, whether the network
     can carry call audio, other open tabs, and the speaker. Logic lives in
     resources/js/voice-check.js (unit-tested); this view only renders it.

     The component logic is deliberately NOT inline here: an Alpine x-data attribute is
     double-quoted, and one stray double quote silently truncates it (it has broken the
     Quick Dial keypad before). --}}
<div x-data="voiceCheck()" @open-voice-check.window="start()" @keydown.escape.window="open = false">
    <button type="button" @click="start()"
            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 active:scale-95 transition">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
        {{ __('Check my voice setup') }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             role="dialog" aria-modal="true" aria-label="{{ __('Voice check') }}">
            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="open = false"></div>

            <div class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-slate-900 px-5 py-4 text-white">
                    <h3 class="font-display text-sm font-bold uppercase tracking-wider">{{ __('Voice check') }}</h3>
                    <button type="button" @click="open = false" class="text-slate-300 hover:text-white" aria-label="{{ __('Close') }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                    {{-- Running --}}
                    <p x-show="running" x-cloak class="flex items-center gap-3 text-sm text-gray-600">
                        <span class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-emerald-600 border-t-transparent"></span>
                        <span x-text="stepNote"></span>
                    </p>

                    {{-- Overall result --}}
                    <p x-show="!running && worst !== 'idle'" x-cloak class="mb-3 text-sm font-semibold"
                       :class="{ 'text-emerald-700': worst === 'ok', 'text-amber-700': worst === 'warn', 'text-red-700': worst === 'fail' }"
                       x-text="worst === 'ok' ? @js(__('Everything checked looks good.')) : (worst === 'warn' ? @js(__('Calls may work, but there is something to look at.')) : @js(__('Something will stop calls from working.')))"></p>

                    {{-- Findings --}}
                    <ul x-show="!running" x-cloak class="space-y-3">
                        <template x-for="finding in findings" :key="finding.title">
                            <li class="flex gap-3">
                                <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs font-bold text-white"
                                      :class="{ 'bg-emerald-500': finding.level === 'ok', 'bg-amber-500': finding.level === 'warn', 'bg-red-500': finding.level === 'fail' }"
                                      x-text="finding.level === 'ok' ? '✓' : (finding.level === 'warn' ? '!' : '✕')"></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900" x-text="finding.title"></p>
                                    <p x-show="finding.detail" class="mt-0.5 text-xs leading-relaxed text-gray-600" x-text="finding.detail"></p>
                                </div>
                            </li>
                        </template>
                    </ul>

                    {{-- Speaker test: plays through the same MediaStream→<audio> path call audio uses. --}}
                    <div x-show="!running" x-cloak class="mt-4 border-t border-gray-100 pt-4">
                        <p class="text-sm font-semibold text-gray-900">{{ __('Sound') }}</p>
                        <p class="mt-0.5 text-xs text-gray-600">{{ __('Plays a short tone the same way call audio is played, so you know you will hear callers.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <button type="button" @click="playTone()" :disabled="tonePlaying"
                                    class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200 disabled:opacity-50">
                                <span x-text="tonePlaying ? @js(__('Playing…')) : @js(__('Play test sound'))"></span>
                            </button>
                            <span x-show="toneAsked" x-cloak class="flex items-center gap-2 text-xs text-gray-700">
                                {{ __('Did you hear it?') }}
                                <button type="button" @click="answerTone(true)" class="rounded bg-emerald-600 px-2 py-1 font-semibold text-white hover:bg-emerald-700">{{ __('Yes') }}</button>
                                <button type="button" @click="answerTone(false)" class="rounded bg-red-600 px-2 py-1 font-semibold text-white hover:bg-red-700">{{ __('No') }}</button>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 border-t border-gray-100 bg-gray-50 px-5 py-3">
                    <button type="button" @click="copyReport()" :disabled="running"
                            class="text-xs font-semibold text-gray-600 hover:text-gray-900 disabled:opacity-50">
                        <span x-text="copied ? @js(__('Copied ✓')) : @js(__('Copy report for support'))"></span>
                    </button>
                    <button type="button" @click="run()" :disabled="running"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                        {{ __('Run again') }}
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
