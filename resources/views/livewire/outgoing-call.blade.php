<div x-data="outgoingCall({
    callId: {{ $call->id }},
    sessionId: @js($call->provider_session_id),
    customerPhone: @js($call->to_phone),
    contactName: @js($call->contact->display_name ?? $call->to_phone),
    csrf: @js(csrf_token()),
})" x-init="init()">
    {{-- Calling / connecting --}}
    <template x-if="state === 'calling' || state === 'connecting'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-6 pt-7 pb-5 flex flex-col items-center">
                <div class="relative">
                    <div class="w-24 h-24 rounded-full grid place-items-center bg-amber-50 ring-4 ring-amber-400 text-amber-600 shadow-sm">
                        <svg class="w-11 h-11" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    </div>
                    <span class="absolute bottom-0 right-0 grid place-items-center w-8 h-8 rounded-full bg-amber-500 text-white ring-2 ring-white animate-pulse">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    </span>
                </div>
                <p class="mt-4 text-lg font-bold text-gray-900 text-center" x-text="contactName"></p>
                <p class="text-sm text-gray-500 font-mono" x-text="customerPhone"></p>
                <div class="mt-2 flex items-center gap-1.5 text-amber-600">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-sm font-semibold">
                        <span x-show="state === 'calling'">{{ __('Calling…') }}</span>
                        <span x-show="state === 'connecting'" x-cloak>{{ __('Connecting…') }}</span>
                    </span>
                </div>
            </div>
            <div class="px-6 pb-6">
                <button type="button" @click="hangup()"
                        class="w-full h-12 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold flex items-center justify-center gap-2 transition active:scale-[.98]">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08a.956.956 0 01-.29-.7c0-.28.11-.53.29-.71C3.34 8.77 7.46 7 12 7s8.66 1.77 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28a11.27 11.27 0 00-2.67-1.85.996.996 0 01-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    </template>

    {{-- Connected --}}
    <template x-if="state === 'connected'">
        @include('livewire.partials.call-card', ['phoneExpr' => 'customerPhone'])
    </template>

    {{-- Failed --}}
    <template x-if="state === 'failed'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-5 py-5">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center w-10 h-10 rounded-full bg-red-100 text-red-600 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-gray-900">{{ __('Could not start call') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Voice provider may be unreachable.') }}</p>
                    </div>
                    <button type="button" @click="dismiss()" class="text-sm text-gray-500 hover:text-gray-700 font-medium">{{ __('Dismiss') }}</button>
                </div>
                <p class="mt-2 text-xs font-mono text-red-800/70 break-all" x-show="errorMessage" x-cloak x-text="errorMessage"></p>
            </div>
        </div>
    </template>
</div>
