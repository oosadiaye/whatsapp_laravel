<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Calls') }}</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        {{-- Header + filters --}}
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4 sm:p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-lg font-bold text-gray-900 tracking-tight">{{ __('Call History') }}</h1>
                <span class="text-xs text-gray-400">{{ $calls->total() }} {{ __('total') }}</span>
            </div>

            {{-- Functional filter chips (direction + status via query string) --}}
            <div class="flex flex-wrap items-center gap-2">
                @php
                    $chip = fn (bool $active, string $on, string $off = 'bg-gray-100 text-gray-600 hover:bg-gray-200')
                        => 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition ' . ($active ? $on : $off);
                @endphp
                <a href="{{ route('calls.index') }}"
                   class="{{ $chip(! $currentDirection && ! $currentStatus, 'bg-gray-900 text-white') }}">{{ __('All') }}</a>
                <a href="{{ route('calls.index', ['direction' => 'inbound']) }}"
                   class="{{ $chip($currentDirection === 'inbound', 'bg-emerald-600 text-white') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                    {{ __('Inbound') }}
                </a>
                <a href="{{ route('calls.index', ['direction' => 'outbound']) }}"
                   class="{{ $chip($currentDirection === 'outbound', 'bg-blue-600 text-white') }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                    {{ __('Outbound') }}
                </a>
                <span class="w-px h-5 bg-gray-200 mx-1"></span>
                <a href="{{ route('calls.index', ['status' => 'missed']) }}"
                   class="{{ $chip($currentStatus === 'missed', 'bg-amber-500 text-white') }}">{{ __('Missed') }}</a>
                <a href="{{ route('calls.index', ['status' => 'failed']) }}"
                   class="{{ $chip($currentStatus === 'failed', 'bg-red-600 text-white') }}">{{ __('Failed') }}</a>
                @if($currentDirection || $currentStatus)
                    <a href="{{ route('calls.index') }}" class="ml-auto text-xs font-semibold text-gray-500 hover:text-gray-800 hover:underline">{{ __('Clear filters') }}</a>
                @endif
            </div>
        </div>

        {{-- Calls table --}}
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Date / Time') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Direction') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Participant') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Trunk') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500 text-right">{{ __('Duration') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                            <th class="px-5 py-3 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Quality') }}</th>
                            <th class="px-5 py-3 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($calls as $call)
                            @php
                                $isMissed = $call->status === 'missed';
                                $statusMeta = match($call->status) {
                                    'connected' => ['dot' => 'bg-emerald-500 animate-pulse', 'text' => 'text-emerald-600', 'label' => __('Active')],
                                    'ended'     => ['dot' => 'bg-slate-400', 'text' => 'text-slate-500', 'label' => __('Ended')],
                                    'missed'    => ['dot' => 'bg-amber-500', 'text' => 'text-amber-600', 'label' => __('Missed')],
                                    'declined'  => ['dot' => 'bg-red-500', 'text' => 'text-red-600', 'label' => __('Declined')],
                                    'failed'    => ['dot' => 'bg-red-500', 'text' => 'text-red-600', 'label' => __('Failed')],
                                    'ringing', 'initiated' => ['dot' => 'bg-blue-500 animate-pulse', 'text' => 'text-blue-600', 'label' => ucfirst($call->status)],
                                    default     => ['dot' => 'bg-gray-400', 'text' => 'text-gray-600', 'label' => ucfirst($call->status)],
                                };
                                $isAt = $call->provider === \App\Models\CallLog::PROVIDER_AFRICAS_TALKING;
                            @endphp
                            <tr class="group hover:bg-gray-50 transition-colors {{ $call->status === 'connected' ? 'bg-emerald-50/40' : '' }}">
                                {{-- Date / time --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="text-sm font-mono text-gray-800">{{ $call->created_at->format('M d, Y') }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ $call->created_at->format('H:i:s') }}</div>
                                </td>
                                {{-- Direction --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if($isMissed)
                                        <span class="inline-flex items-center gap-1.5 text-sm text-amber-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H5v4m0-4l6 6m5 5h4v-4m0 4l-6-6"/></svg>
                                            {{ __('Missed') }}
                                        </span>
                                    @elseif($call->isInbound())
                                        <span class="inline-flex items-center gap-1.5 text-sm text-emerald-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5v4H7m4 0L5 3m14 11v4h-4m4 0l-6-6"/></svg>
                                            {{ __('Inbound') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-sm text-blue-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5h4v4m0-4l-6 6M9 19H5v-4m0 4l6-6"/></svg>
                                            {{ __('Outbound') }}
                                        </span>
                                    @endif
                                </td>
                                {{-- Participant --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="font-semibold text-gray-900">{{ $call->contact ? $call->contact->display_name : ($call->isInbound() ? $call->from_phone : $call->to_phone) }}</div>
                                    <div class="text-xs text-gray-400 font-mono">{{ $call->isInbound() ? $call->from_phone : $call->to_phone }}</div>
                                </td>
                                {{-- Trunk / instance --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border
                                                 {{ $isAt ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">
                                        {{ $isAt ? "Africa's Talking" : 'Meta' }}
                                    </span>
                                    <div class="text-[11px] text-gray-400 mt-0.5">{{ $call->whatsappInstance->display_name ?? $call->whatsappInstance->instance_name ?? '—' }}</div>
                                </td>
                                {{-- Duration --}}
                                <td class="px-5 py-3 whitespace-nowrap text-right font-mono text-sm text-gray-700">
                                    {{ $call->duration_seconds ? gmdate('H:i:s', $call->duration_seconds) : '—' }}
                                </td>
                                {{-- Status --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full {{ $statusMeta['dot'] }}"></span>
                                        <span class="text-[11px] font-bold uppercase tracking-wide {{ $statusMeta['text'] }}">{{ $statusMeta['label'] }}</span>
                                    </div>
                                </td>
                                {{-- Quality (app-specific MOS chip) --}}
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if($call->quality_metrics)
                                        @php
                                            $mos = $call->quality_metrics['mos'] ?? null;
                                            $qColor = match (true) {
                                                $mos === null => 'bg-gray-100 text-gray-600',
                                                $mos >= 4.0 => 'bg-emerald-100 text-emerald-800',
                                                $mos >= 3.0 => 'bg-amber-100 text-amber-800',
                                                default => 'bg-red-100 text-red-800',
                                            };
                                            $qLabel = match (true) {
                                                $mos === null => '—',
                                                $mos >= 4.0 => __('Excellent'),
                                                $mos >= 3.0 => __('Fair'),
                                                default => __('Poor'),
                                            };
                                            $tooltip = sprintf('MOS %s · jitter %sms · loss %s%% · RTT %sms · ICE %s',
                                                $mos ?? '?', $call->quality_metrics['avg_jitter_ms'] ?? '?',
                                                $call->quality_metrics['avg_packet_loss_pct'] ?? '?',
                                                $call->quality_metrics['avg_rtt_ms'] ?? '?',
                                                $call->quality_metrics['ice_candidate_type'] ?? '?');
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $qColor }}" title="{{ $tooltip }}">
                                            {{ $qLabel }} {{ $mos !== null ? number_format($mos, 1) : '' }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>
                                {{-- Action --}}
                                <td class="px-5 py-3 whitespace-nowrap text-right">
                                    @if($call->conversation_id)
                                        <a href="{{ route('conversations.show', $call->conversation_id) }}"
                                           class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-700 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity">
                                            {{ __('Open') }}
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-16 text-gray-400">
                                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                    <p>{{ __('No calls match this filter.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($calls->hasPages())
                <div class="px-5 py-3 border-t border-gray-100 bg-gray-50/50">{{ $calls->links() }}</div>
            @endif
        </div>

        {{-- Trend widgets (real, visibility-scoped data) --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {{-- Calls today --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Calls today') }}</span>
                    <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"/></svg>
                </div>
                <div class="mt-3 text-3xl font-bold text-gray-900 tabular-nums">{{ $stats['todayCount'] }}</div>
            </div>
            {{-- Avg duration --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Avg. duration') }}</span>
                    <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="mt-3 text-3xl font-bold text-gray-900 font-mono tabular-nums">
                    {{ $stats['avgDurationSeconds'] ? gmdate('i:s', $stats['avgDurationSeconds']) : '—' }}
                </div>
            </div>
            {{-- Provider distribution --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('By provider') }}</span>
                    <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/></svg>
                </div>
                @if($stats['providerTotal'] > 0)
                    <div class="space-y-2.5">
                        @foreach(['africastalking' => "Africa's Talking", 'meta_whatsapp' => 'Meta'] as $key => $label)
                            @php $pct = (int) round($stats['providerCounts']->get($key, 0) / $stats['providerTotal'] * 100); @endphp
                            <div>
                                <div class="flex items-center justify-between text-sm text-gray-700">
                                    <span>{{ $label }}</span><span class="font-bold tabular-nums">{{ $pct }}%</span>
                                </div>
                                <div class="mt-1 w-full h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full {{ $key === 'africastalking' ? 'bg-emerald-500' : 'bg-gray-800' }}" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400">{{ __('No calls yet.') }}</p>
                @endif
            </div>
        </div>

        {{-- Observability metrics (today, visibility-scoped) --}}
        <div class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Answer rate') }}</span>
                <div class="mt-3 text-3xl font-bold text-gray-900 tabular-nums">{{ $stats['answerRate'] === null ? '—' : $stats['answerRate'].'%' }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ $stats['answered'] }} {{ __('answered') }} · {{ $stats['missed'] }} {{ __('missed') }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Avg. time to answer') }}</span>
                <div class="mt-3 text-3xl font-bold text-gray-900 font-mono tabular-nums">{{ $stats['avgTimeToAnswerSeconds'] === null ? '—' : gmdate('i:s', $stats['avgTimeToAnswerSeconds']) }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Avg. MOS') }}</span>
                <div class="mt-3 text-3xl font-bold tabular-nums {{ $stats['avgMos'] === null ? 'text-gray-900' : ($stats['avgMos'] >= 4 ? 'text-emerald-600' : ($stats['avgMos'] >= 3 ? 'text-amber-600' : 'text-red-600')) }}">{{ $stats['avgMos'] ?? '—' }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ __('call quality (1–5)') }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __("Today's outcomes") }}</span>
                <div class="mt-3 space-y-1 text-sm">
                    @foreach(['ended' => 'Ended', 'missed' => 'Missed', 'declined' => 'Declined', 'failed' => 'Failed'] as $k => $label)
                        @php $c = (int) ($stats['statusBreakdown'][$k] ?? 0); @endphp
                        @if($c > 0)
                            <div class="flex justify-between"><span class="text-gray-500">{{ __($label) }}</span><span class="font-bold tabular-nums {{ in_array($k, ['missed', 'failed']) ? 'text-red-600' : 'text-gray-800' }}">{{ $c }}</span></div>
                        @endif
                    @endforeach
                    @if($stats['statusBreakdown']->sum() === 0)
                        <span class="text-gray-400">—</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
