<div>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-gray-700">{{ __('Call Costs') }}</h3>
        <select wire:model.live="period" class="text-xs border border-gray-200 rounded-lg px-2 py-1 focus:border-blue-400 focus:ring focus:ring-blue-200">
            <option value="today">{{ __('Today') }}</option>
            <option value="week">{{ __('This week') }}</option>
            <option value="month">{{ __('This month') }}</option>
        </select>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[11px] text-gray-500 uppercase tracking-wide font-semibold">{{ __('Calls') }}</p>
            <p class="text-xl font-bold text-gray-900 mt-1">{{ $totalCalls }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[11px] text-gray-500 uppercase tracking-wide font-semibold">{{ __('Duration') }}</p>
            <p class="text-xl font-bold text-gray-900 mt-1 font-data">{{ sprintf('%dh %dm', intdiv($totalDuration, 3600), intdiv($totalDuration % 3600, 60)) }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[11px] text-gray-500 uppercase tracking-wide font-semibold">{{ __('Cost') }}</p>
            <p class="text-xl font-bold text-gray-900 mt-1">{{ number_format($totalCost / 100, 2) }} {{ session('currency', 'KES') }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[11px] text-gray-500 uppercase tracking-wide font-semibold">{{ __('Avg / call') }}</p>
            <p class="text-xl font-bold text-gray-900 mt-1">{{ number_format($avgCost / 100, 2) }} {{ session('currency', 'KES') }}</p>
        </div>
    </div>

    @if($recentCalls->isNotEmpty())
        <div class="border-t border-gray-100 pt-3">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ __('Recent') }}</p>
            <div class="space-y-1">
                @foreach($recentCalls as $call)
                    <div class="flex items-center justify-between text-xs py-1">
                        <span class="text-gray-700 truncate">{{ $call->contact?->name ?? $call->from_phone ?? __('Unknown') }}</span>
                        <span class="text-gray-500 font-data">{{ $call->duration_seconds ? sprintf('%d:%02d', intdiv($call->duration_seconds, 60), $call->duration_seconds % 60) : '—' }}</span>
                        <span class="text-gray-700 font-medium">{{ number_format(($call->cost_estimate_kobo ?? 0) / 100, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
