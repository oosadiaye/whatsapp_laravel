<div wire:poll.3s x-data="realtimePulse()" x-init="init()">
    {{-- RealtimePulse: real-time UX layer for inbound calls + chat notifications.
         Mounted on the layout via @auth in app.blade.php. The Alpine factory
         (window.realtimePulse) lives in resources/js/app.js and consumes the
         data attributes set below. --}}

    {{-- Banner stack: up to 3 in-flight inbound calls, sticky top of viewport --}}
    @forelse($inflightCalls as $call)
        <div class="sticky top-0 z-40 bg-emerald-600 text-white px-4 py-3 shadow-md flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xl animate-pulse" aria-hidden="true">📞</span>
                <div>
                    <div class="font-semibold">
                        Incoming call from {{ $call['contact_name'] ?? 'Unknown' }}
                    </div>
                    <div class="text-xs text-emerald-100 font-mono">
                        {{ $call['phone'] }} · ringing on {{ $call['instance_name'] }}
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('conversations.show', $call['conversation_id']) }}"
                   class="bg-white text-emerald-700 px-3 py-1.5 rounded-md text-sm font-medium hover:bg-emerald-50">
                    Open conversation →
                </a>
            </div>
        </div>
    @empty
        {{-- nothing ringing right now --}}
    @endforelse

    {{-- Hidden audio elements, played from JS on incoming-call / new-message events.
         Two separate elements so the call ringtone (loops until call ends) and the
         message ping (one-shot per unread delta) can play independently without
         colliding. Both point at the same MP3 — browser cache serves the second
         request, so no extra network cost. --}}
    <audio id="bq-ringtone" preload="auto" loop>
        <source src="{{ asset('audio/incoming-call.mp3') }}" type="audio/mpeg">
    </audio>
    <audio id="bq-message-ping" preload="auto">
        <source src="{{ asset('audio/incoming-call.mp3') }}" type="audio/mpeg">
    </audio>

    {{-- Data carrier for the Alpine factory in resources/js/app.js.
         The factory reads these attributes after each poll to detect
         changes (new calls, message-count delta) and dispatch side-effects.

         WHY {{ }} (not {!! !!}) IS CORRECT HERE despite emitting JSON:
         Blade {{ }} calls e() which HTML-encodes \" -> &quot;, then the
         browser HTML attribute parser decodes &quot; -> \" when reading
         dataset.calls, yielding valid JSON for JSON.parse. Switching to
         {!! !!} would emit raw \" inside a \"-delimited attribute, breaking
         the HTML and creating an XSS surface for a contact name like
         <script>...</script>. Verified by reviewer round-trip test. --}}
    <span id="bq-realtime-data"
          data-calls="{{ json_encode($inflightCalls) }}"
          data-unread="{{ $unreadMessages }}"
          aria-hidden="true"></span>
</div>
