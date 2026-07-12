@php
    $fmt = fn (?int $s) => sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
    $presenceDot = [
        \App\Models\User::PRESENCE_AVAILABLE => 'bg-emerald-500',
        \App\Models\User::PRESENCE_BUSY => 'bg-orange-500',
        \App\Models\User::PRESENCE_AWAY => 'bg-gray-400',
    ];
@endphp

<div wire:poll.5s class="space-y-6">

    {{-- KPI tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach ([
            ['label' => __('Live now'), 'value' => $stats['live'], 'accent' => $stats['live'] > 0 ? 'text-emerald-600' : 'text-gray-900'],
            ['label' => __('Calls today'), 'value' => $stats['today'], 'accent' => 'text-gray-900'],
            ['label' => __('Answered'), 'value' => $stats['answered'], 'accent' => 'text-gray-900'],
            ['label' => __('Missed'), 'value' => $stats['missed'], 'accent' => $stats['missed'] > 0 ? 'text-red-600' : 'text-gray-900'],
            ['label' => __('Answer rate'), 'value' => $stats['answer_rate'] === null ? '—' : $stats['answer_rate'].'%', 'accent' => 'text-gray-900'],
            ['label' => __('Avg talk'), 'value' => $fmt($stats['avg_talk_seconds']), 'accent' => 'text-gray-900'],
        ] as $tile)
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
                <div class="text-2xl font-bold {{ $tile['accent'] }}">{{ $tile['value'] }}</div>
                <div class="text-[11px] uppercase tracking-wide text-gray-400 mt-0.5">{{ $tile['label'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        {{-- Live calls --}}
        <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full {{ $stats['live'] > 0 ? 'bg-emerald-500 animate-pulse' : 'bg-gray-300' }}"></span>
                <h3 class="text-sm font-bold text-gray-700">{{ __('Live calls') }}</h3>
                <span class="ml-auto text-xs text-gray-400">{{ $stats['avg_mos'] !== null ? __('avg MOS').' '.$stats['avg_mos'] : '' }}</span>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($liveCalls as $call)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <span class="grid place-items-center w-9 h-9 rounded-full shrink-0 {{ $call->direction === 'inbound' ? 'bg-sky-50 text-sky-600' : 'bg-violet-50 text-violet-600' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-sm text-gray-900 truncate">{{ $call->contact?->name ?? $call->to_phone ?? __('Unknown') }}</div>
                            <div class="text-xs text-gray-400">
                                {{ ucfirst($call->direction) }}
                                @if($call->placedBy) · {{ $call->placedBy->name }} @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold
                            {{ $call->status === 'connected' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $call->status === 'connected' ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse' }}"></span>
                            {{ ucfirst($call->status) }}
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-gray-400">{{ __('No calls in progress right now.') }}</div>
                @endforelse
            </div>
        </div>

        {{-- Agent grid --}}
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-700">{{ __('Agents') }}</h3>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($agents as $agent)
                    @php $onCall = in_array($agent->id, $onCallUserIds, true); @endphp
                    <div class="flex items-center gap-3 px-5 py-2.5">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $onCall ? 'bg-violet-500' : ($presenceDot[$agent->presence_status] ?? 'bg-gray-300') }}"></span>
                        <span class="text-sm text-gray-800 truncate flex-1">{{ $agent->name }}</span>
                        <span class="text-[11px] font-medium {{ $onCall ? 'text-violet-600' : 'text-gray-400' }}">
                            {{ $onCall ? __('On call') : ucfirst($agent->presence_status ?? __('offline')) }}
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">{{ __('No active agents.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
