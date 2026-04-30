@php
    /** @var \App\Models\CallLog $call */

    $isInbound = $call->isInbound();
    $borderClass = match($call->status) {
        'connected', 'ended' => 'border-emerald-200 bg-emerald-50',
        'missed' => 'border-amber-200 bg-amber-50',
        'declined', 'failed' => 'border-red-200 bg-red-50',
        default => 'border-blue-200 bg-blue-50',
    };
    $iconClass = match($call->status) {
        'connected', 'ended' => 'text-emerald-700',
        'missed' => 'text-amber-700',
        'declined', 'failed' => 'text-red-700',
        default => 'text-blue-700',
    };
    $directionLabel = $isInbound ? 'Inbound call' : 'Outbound call';
    $statusLabel = match($call->status) {
        'ended' => $call->duration_seconds > 0
            ? gmdate('i:s', $call->duration_seconds)
            : 'Ended',
        'missed' => 'No answer',
        'declined' => 'Declined',
        'failed' => 'Failed',
        'ringing' => 'Ringing...',
        'connected' => 'Connected · live',
        'initiated' => 'Connecting...',
        default => $call->status,
    };
@endphp

<div class="flex justify-center my-2">
    <div class="rounded-lg border {{ $borderClass }} px-4 py-2.5 text-sm max-w-md">
        <div class="flex items-center gap-3">
            <svg class="w-4 h-4 flex-shrink-0 {{ $iconClass }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                @if($isInbound)
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                @endif
            </svg>
            <span class="font-medium text-gray-800">{{ $directionLabel }}</span>
            <span class="{{ $iconClass }}">·</span>
            <span class="text-gray-700">{{ $statusLabel }}</span>
            <span class="text-xs text-gray-500 ml-auto">{{ $call->created_at->format('H:i') }}</span>
        </div>

        @if($call->placedBy && ! $isInbound)
            <p class="mt-1 text-xs text-gray-500">Placed by {{ $call->placedBy->name }}</p>
        @endif

        @if($call->failure_reason)
            <p class="mt-1 text-xs text-red-700">{{ $call->failure_reason }}</p>
        @endif
    </div>
</div>
