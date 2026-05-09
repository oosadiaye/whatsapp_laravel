<div wire:poll.3s x-data="realtimePulse()" x-init="init()">
    {{-- RealtimePulse: real-time UX layer for inbound calls + chat notifications.
         Mounted on the layout via @auth in app.blade.php. The Alpine factory
         (window.realtimePulse) lives in resources/js/app.js and consumes the
         data attributes set below. --}}

    {{-- Banner stack: up to 3 in-flight inbound calls, sticky top of viewport.
         Phase 17 replaces the prior "Open conversation" button with the
         IncomingCall Livewire component, which owns Accept/Decline/in-call
         WebRTC UI. --}}
    @forelse($inflightCalls as $call)
        @php $callLog = \App\Models\CallLog::find($call['id']); @endphp
        @if($callLog)
            <livewire:incoming-call :call="$callLog" :wire:key="'call-'.$callLog->id" />
        @endif
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
          data-missed-calls="{{ $missedCallsCount }}"
          aria-hidden="true"></span>
</div>
