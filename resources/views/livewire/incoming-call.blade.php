@php($_isAt = $call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING)
@php($_isMeta = $call->provider === \App\Models\CallLog::PROVIDER_META_WHATSAPP)
@if($_isMeta)
    {{-- The Meta Cloud Calling API is not generally available, and the
         send/accept endpoints in WhatsAppCloudApiService are flagged as
         speculative. Rather than attempt a WebRTC handshake that can never
         succeed (and fail opaquely with "Missing SDP offer"), show a clear
         notice. Live calls use the Africa's Talking dialer (provider=
         africastalking). --}}
    <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
        <div class="flex items-start gap-3 px-5 py-4 text-sm text-gray-600">
            <span class="grid place-items-center w-9 h-9 rounded-full bg-gray-100 text-gray-500 shrink-0">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            </span>
            <span>Incoming WhatsApp call — live answering isn't available in this build (Meta Cloud Calling API not enabled). Use the Africa's Talking dialer for real-time calls.</span>
        </div>
    </div>
@else
<div @if($_isAt) x-data="incomingAtCall({
    callId: {{ $call->id }},
    sessionId: @js(session()->getId()),
    contactName: @js($call->contact->display_name ?? 'Unknown'),
    phone: @js($call->from_phone),
    csrf: @js(csrf_token()),
})" @else x-data="incomingCall({
    callId: {{ $call->id }},
    metaCallId: @js($call->meta_call_id),
    sdpOffer: @js($call->sdp_offer),
    sessionId: @js(session()->getId()),
    contactName: @js($call->contact->display_name ?? 'Unknown'),
    phone: @js($call->from_phone),
    csrf: @js(csrf_token()),
})" @endif x-init="init()">
    {{-- Phase 18: same DOM serves both providers; the x-data factory differs
          (raw WebRTC vs AT SDK) but the state names (ringing/connecting/
          connected/mic_denied/claimed_elsewhere) and method names
          (acceptCall/declineCall/toggleMute/toggleHold/toggleKeypad/hangup)
          are intentionally identical so this markup works for both. --}}

    {{-- Ringing --}}
    <template x-if="state === 'ringing'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-6 pt-7 pb-5 flex flex-col items-center">
                <div class="relative">
                    <span class="absolute -inset-1.5 rounded-full bg-emerald-400/30 animate-ping"></span>
                    <div class="relative w-24 h-24 rounded-full grid place-items-center bg-emerald-50 ring-4 ring-emerald-500 text-emerald-600 shadow-sm">
                        <svg class="w-11 h-11" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    </div>
                    <span class="absolute bottom-0 right-0 z-10 grid place-items-center w-8 h-8 rounded-full bg-emerald-500 text-white ring-2 ring-white animate-pulse">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    </span>
                </div>
                <p class="mt-4 text-[11px] font-bold uppercase tracking-widest text-emerald-600">{{ __('Incoming call') }}</p>
                <p class="mt-1 text-lg font-bold text-gray-900 text-center" x-text="contactName"></p>
                <p class="text-sm text-gray-500 font-mono" x-text="phone"></p>
            </div>
            <div class="px-6 pb-6 grid grid-cols-2 gap-3">
                <button type="button" @click="declineCall()"
                        class="h-12 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold flex items-center justify-center gap-2 transition active:scale-[.98]">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08a.956.956 0 01-.29-.7c0-.28.11-.53.29-.71C3.34 8.77 7.46 7 12 7s8.66 1.77 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28a11.27 11.27 0 00-2.67-1.85.996.996 0 01-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
                    {{ __('Decline') }}
                </button>
                <button type="button" @click="acceptCall()"
                        class="h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold flex items-center justify-center gap-2 transition active:scale-[.98]">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    {{ __('Accept') }}
                </button>
            </div>
        </div>
    </template>

    {{-- Connecting --}}
    <template x-if="state === 'connecting'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-6 py-8 flex flex-col items-center">
                <div class="w-16 h-16 rounded-full bg-amber-50 ring-4 ring-amber-300 text-amber-600 grid place-items-center animate-pulse">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                </div>
                <p class="mt-3 text-sm font-semibold text-amber-700">{{ __('Connecting to') }} <span x-text="contactName"></span>…</p>
            </div>
        </div>
    </template>

    {{-- Connected --}}
    <template x-if="state === 'connected'">
        @include('livewire.partials.call-card', ['phoneExpr' => 'phone'])
    </template>

    {{-- Microphone denied --}}
    <template x-if="state === 'mic_denied'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-5 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid place-items-center w-10 h-10 rounded-full bg-red-100 text-red-600 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M3 3l18 18M9.75 9.348A3 3 0 019 7.5V4.5a3 3 0 015.856-.917"/></svg>
                    </span>
                    <div class="flex-1 text-sm">
                        <p class="font-semibold text-gray-900">{{ __('Microphone blocked') }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('Allow mic access in the browser prompt (or the camera/mic icon in the address bar), then try again.') }}</p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button type="button" @click="declineCall()" class="h-11 rounded-xl border border-gray-200 text-gray-700 font-medium hover:bg-gray-50 transition">{{ __('Decline') }}</button>
                    <button type="button" @click="retryAccept()" class="h-11 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 transition">{{ __('Try again') }}</button>
                </div>
            </div>
        </div>
    </template>

    {{-- Connect failed --}}
    <template x-if="state === 'connect_failed'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="px-5 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid place-items-center w-10 h-10 rounded-full bg-amber-100 text-amber-600 shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                    </span>
                    <div class="flex-1 text-sm">
                        <p class="font-semibold text-gray-900">{{ __("Couldn't connect the call") }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('The customer may still be ringing — try again, or decline to release.') }}</p>
                    </div>
                </div>
                <p class="mt-2 text-xs font-mono text-amber-800/70 break-all" x-show="errorMessage" x-cloak x-text="errorMessage"></p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button type="button" @click="declineCall()" class="h-11 rounded-xl border border-gray-200 text-gray-700 font-medium hover:bg-gray-50 transition">{{ __('Decline') }}</button>
                    <button type="button" @click="retryAccept()" class="h-11 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 transition">{{ __('Try again') }}</button>
                </div>
            </div>
        </div>
    </template>

    {{-- Claimed elsewhere --}}
    <template x-if="state === 'claimed_elsewhere'">
        <div class="fixed bottom-5 right-5 z-50 w-[360px] max-w-[calc(100vw-2.5rem)] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-4 text-sm text-gray-600">
                <span class="grid place-items-center w-8 h-8 rounded-full bg-gray-100 text-gray-500 shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                {{ __('Call answered in another window or device.') }}
            </div>
        </div>
    </template>

    <audio id="bq-remote-audio" autoplay></audio>
</div>
@endif
