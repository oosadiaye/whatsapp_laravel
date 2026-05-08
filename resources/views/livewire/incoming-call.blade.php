@php($_isAt = $call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING)
<div @if($_isAt) x-data="incomingAtCall({
    callId: {{ $call->id }},
    sessionId: @js(session()->getId()),
    contactName: @js($call->contact->display_name ?? 'Unknown'),
    phone: @js($call->from_phone),
    atToken: @js($atToken),
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
    {{-- Phase 18: same template DOM serves both providers; the x-data
         factory above differs (raw WebRTC vs AT SDK) but the state
         names (ringing/connecting/connected/mic_denied/claimed_elsewhere)
         and method names (acceptCall/declineCall/toggleMute/hangup) are
         intentionally identical so the markup below works for both. --}}
    <template x-if="state === 'ringing'">
        <div class="flex items-center gap-3 bg-emerald-600 text-white px-4 py-3 shadow-md">
            <span class="text-xl animate-pulse" aria-hidden="true">📞</span>
            <div class="flex-1">
                <div class="font-semibold" x-text="`Incoming call from ${contactName}`"></div>
                <div class="text-xs text-emerald-100 font-mono" x-text="phone"></div>
            </div>
            <button @click="acceptCall()"
                    class="bg-white text-emerald-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-emerald-50">
                Accept
            </button>
            <button @click="declineCall()"
                    class="bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-red-700">
                Decline
            </button>
        </div>
    </template>

    <template x-if="state === 'connecting'">
        <div class="bg-amber-100 border-b border-amber-300 text-amber-900 px-4 py-3">
            <span>Connecting to <span x-text="contactName"></span>...</span>
        </div>
    </template>

    <template x-if="state === 'connected'">
        <div class="flex items-center justify-between bg-emerald-100 border-b border-emerald-300 text-emerald-900 px-4 py-3">
            <span>
                On call: <span x-text="contactName" class="font-semibold"></span>
                · <span x-text="formatDuration(durationSeconds)"></span>
            </span>
            <div class="flex items-center gap-2">
                <button @click="toggleMute()"
                        class="px-3 py-1.5 rounded text-sm font-medium"
                        :class="muted ? 'bg-amber-600 text-white' : 'bg-white text-emerald-700 border border-emerald-300'"
                        x-text="muted ? 'Unmute' : 'Mute'"></button>
                <button @click="hangup()"
                        class="bg-red-600 text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-red-700">
                    Hang up
                </button>
            </div>
        </div>
    </template>

    <template x-if="state === 'mic_denied'">
        <div class="bg-red-100 border-b border-red-300 text-red-900 px-4 py-3 text-sm">
            Microphone access required to answer calls. Click the lock icon in your browser address bar
            to grant permission, then reload the page.
        </div>
    </template>

    <template x-if="state === 'claimed_elsewhere'">
        <div class="bg-gray-100 border-b border-gray-300 text-gray-700 px-4 py-3 text-sm">
            Call answered in another window or device.
        </div>
    </template>

    <audio id="bq-remote-audio" autoplay></audio>
</div>
