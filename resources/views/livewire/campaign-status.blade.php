<div wire:poll.3s class="space-y-6">
    {{-- Status Badge --}}
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-800">Campaign Status</h3>
        @php
            $statusBadge = match($status) {
                'DRAFT' => 'bg-gray-100 text-gray-700',
                'QUEUED' => 'bg-yellow-100 text-yellow-700',
                'RUNNING' => 'bg-blue-100 text-blue-700',
                'PAUSED' => 'bg-orange-100 text-orange-700',
                'COMPLETED' => 'bg-green-100 text-green-700',
                'FAILED' => 'bg-red-100 text-red-700',
                'CANCELLED' => 'bg-gray-100 text-gray-700',
                default => 'bg-gray-100 text-gray-700',
            };
        @endphp
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold {{ $statusBadge }}">
            {{ $status }}
        </span>
    </div>

    {{-- Progress Bar --}}
    @php
        $progressPercent = $total > 0 ? (($sent + $failed) / $total) * 100 : 0;
    @endphp
    <div>
        <div class="flex items-center justify-between text-sm text-gray-500 mb-2">
            <span>Progress</span>
            <span class="font-medium text-gray-700">{{ number_format($progressPercent, 1) }}%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
            <div class="h-3 rounded-full bg-[#25D366] transition-all duration-500 ease-out"
                 style="width: {{ min($progressPercent, 100) }}%"></div>
        </div>
        <p class="text-xs text-gray-400 mt-1">{{ $sent + $failed }} of {{ $total }} messages processed</p>
    </div>

    {{-- Stat Cards Grid --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Sent --}}
        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                <span class="text-xs font-medium text-blue-600 uppercase tracking-wide">Sent</span>
            </div>
            <p class="text-2xl font-bold text-blue-700">{{ number_format($sent) }}</p>
            <p class="text-xs text-blue-400 mt-0.5">/ {{ number_format($total) }}</p>
        </div>

        {{-- Delivered --}}
        <div class="bg-green-50 border border-green-100 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-green-500"></div>
                <span class="text-xs font-medium text-green-600 uppercase tracking-wide">Delivered</span>
            </div>
            <p class="text-2xl font-bold text-green-700">{{ number_format($delivered) }}</p>
        </div>

        {{-- Read --}}
        <div class="bg-purple-50 border border-purple-100 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-purple-500"></div>
                <span class="text-xs font-medium text-purple-600 uppercase tracking-wide">Read</span>
            </div>
            <p class="text-2xl font-bold text-purple-700">{{ number_format($read) }}</p>
        </div>

        {{-- Failed --}}
        <div class="bg-red-50 border border-red-100 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-red-500"></div>
                <span class="text-xs font-medium text-red-600 uppercase tracking-wide">Failed</span>
            </div>
            <p class="text-2xl font-bold text-red-700">{{ number_format($failed) }}</p>
        </div>
    </div>

    {{-- Rates --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-white border border-gray-200 rounded-xl p-4 text-center">
            <p class="text-sm text-gray-500 mb-1">Delivery Rate</p>
            <p class="text-2xl font-bold text-[#25D366]">{{ number_format($deliveryRate, 1) }}%</p>
        </div>
        <div class="bg-white border border-gray-200 rounded-xl p-4 text-center">
            <p class="text-sm text-gray-500 mb-1">Read Rate</p>
            <p class="text-2xl font-bold text-purple-600">{{ number_format($readRate, 1) }}%</p>
        </div>
    </div>
</div>
