<div wire:poll.3s>
    @if($call)
        @php
            $bannerClass = match($call->status) {
                'connected' => 'bg-emerald-100 border-emerald-300 text-emerald-900',
                default => 'bg-amber-100 border-amber-300 text-amber-900',
            };
            $statusText = match($call->status) {
                'initiated' => 'Connecting to Meta...',
                'ringing' => 'Calling ' . ($call->contact->name ?? $call->to_phone) . '...',
                'connected' => 'Call connected · ' . gmdate('i:s', max(0, (int) now()->diffInSeconds($call->connected_at))),
                default => $call->status,
            };
        @endphp

        <div class="sticky top-0 z-10 border-b {{ $bannerClass }} px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                @if($call->status !== 'connected')
                    <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                @else
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                    </svg>
                @endif
                <span class="font-medium">{{ $statusText }}</span>
            </div>

            @if($call->isInFlight())
                <form method="POST" action="{{ route('conversations.endCall', ['conversation' => $call->conversation_id, 'call' => $call->id]) }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium hover:underline">
                        End call
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
