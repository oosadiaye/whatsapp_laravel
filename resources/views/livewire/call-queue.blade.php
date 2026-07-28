<div wire:poll.5s>
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-700">{{ __('Queue') }}</h3>
        @if($queueEntries->isNotEmpty())
            <span class="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-semibold">{{ $queueEntries->count() }} {{ __('waiting') }}</span>
        @endif
    </div>

    @if($queueEntries->isEmpty())
        <div class="px-5 py-8 text-center text-sm text-gray-400">
            {{ __('No callers in queue.') }}
        </div>
    @else
        <div class="divide-y divide-gray-50">
            @foreach($queueEntries as $index => $entry)
                <div class="flex items-center gap-3 px-5 py-3">
                    <span class="w-7 h-7 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-xs font-bold shrink-0">
                        #{{ $index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <span class="text-sm font-medium text-gray-800">
                            {{ $entry->caller_number ?? __('Unknown') }}
                        </span>
                        <div class="text-xs text-gray-400">
                            {{ $entry->entered_at->diffForHumans() }}
                        </div>
                    </div>
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse shrink-0"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
