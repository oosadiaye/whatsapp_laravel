<div x-data="outgoingCall({
    callId: {{ $call->id }},
    sessionId: @js($call->provider_session_id),
    customerPhone: @js($call->to_phone),
    contactName: @js($call->contact->display_name ?? $call->to_phone),
    atToken: @js($atToken),
    csrf: @js(csrf_token()),
})" x-init="init()">
    <template x-if="state === 'calling'">
        <div class="flex items-center justify-between bg-amber-100 border-b border-amber-300 text-amber-900 px-4 py-3 shadow-md">
            <div>
                <span class="font-medium">Calling <span x-text="contactName"></span></span>
                <span class="ml-3 text-sm font-mono" x-text="formatDuration(durationSeconds)"></span>
            </div>
            <button @click="hangup()"
                    class="bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-red-700">
                Cancel
            </button>
        </div>
    </template>

    <template x-if="state === 'connected'">
        <div class="flex items-center justify-between bg-emerald-100 border-b border-emerald-300 text-emerald-900 px-4 py-3 shadow-md">
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

    <template x-if="state === 'failed'">
        <div class="flex items-center justify-between bg-red-100 border-b border-red-300 text-red-900 px-4 py-3 text-sm">
            <span>Could not start call. Voice provider may be unreachable.</span>
            <button @click="dismiss()" class="px-3 py-1.5 text-sm">Dismiss</button>
        </div>
    </template>
</div>
