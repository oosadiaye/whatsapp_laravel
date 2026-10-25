<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php
        $tz = config('app.business_timezone');
        $hour = (int) now($tz)->format('G');
        $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ');

        $needsYou = $missedCalls + $unassignedChats + $failedToday;
        $summary = collect([
            $liveCalls->count() > 0 ? trans_choice(':count call is live|:count calls are live', $liveCalls->count()) : null,
            $sendingCampaigns > 0 ? trans_choice(':count campaign is sending|:count campaigns are sending', $sendingCampaigns) : null,
            $needsYou > 0 ? trans_choice(':count thing needs you|:count things need you', $needsYou) : null,
        ])->filter()->implode(' · ');

        $messagesDelta = $messagesYesterday > 0
            ? (int) round((($messagesToday - $messagesYesterday) / $messagesYesterday) * 100)
            : null;

        $statusBadge = fn (string $status) => match ($status) {
            'RUNNING' => 'bg-blue-100 text-blue-700',
            'QUEUED' => 'bg-yellow-100 text-yellow-700',
            'PAUSED' => 'bg-orange-100 text-orange-700',
            'COMPLETED' => 'bg-green-100 text-green-700',
            'FAILED' => 'bg-red-100 text-red-700',
            default => 'bg-gray-100 text-gray-700',
        };
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Greeting --}}
            <section class="flex flex-wrap items-end gap-4 pb-2">
                <div class="flex-1 min-w-[260px]">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">{{ now($tz)->translatedFormat('l j F') }}</p>
                    <h1 class="mt-1 font-display text-4xl font-bold tracking-tight text-gray-900 leading-tight">{{ $greeting }}, {{ $firstName }}.</h1>
                    <p class="mt-2 text-gray-600">{{ $summary !== '' ? $summary : __('Nothing needs your attention right now.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @can('calls.dial')
                        <a href="{{ route('calls.workspace') }}" wire:navigate
                           class="inline-flex items-center gap-2 h-11 px-4 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-800 hover:bg-gray-50">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/></svg>
                            {{ __('Quick dial') }}
                        </a>
                    @endcan
                    @can('campaigns.create')
                        <a href="{{ route('campaigns.create') }}" wire:navigate
                           class="inline-flex items-center gap-2 h-11 px-5 rounded-lg bg-brand text-brand-ink text-sm font-bold hover:bg-brand-hover">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            {{ __('New campaign') }}
                        </a>
                    @endcan
                </div>
            </section>

            <div class="flex flex-wrap gap-5 items-stretch">

                {{-- Live now (calls) --}}
                @if($canSeeCalls)
                    <section aria-labelledby="live-h" class="flex-1 basis-[380px] min-w-0 self-start bg-white border border-gray-200 rounded-2xl shadow-sm p-5 flex flex-col gap-4">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full {{ $liveCalls->isNotEmpty() ? 'bg-brand animate-pulse' : 'bg-gray-300' }}"></span>
                            <h2 id="live-h" class="font-display text-xl font-bold tracking-tight text-gray-900">{{ __('Live now') }}</h2>
                            <a href="{{ route('calls.workspace') }}" wire:navigate class="ml-auto -my-2 py-2 text-sm font-semibold text-brand-text hover:underline">{{ __('Call workspace') }} →</a>
                        </div>

                        <ul class="flex flex-col gap-2.5">
                            @forelse($liveCalls as $call)
                                @php $connected = $call->status === \App\Models\CallLog::STATUS_CONNECTED; @endphp
                                <li class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
                                    <span class="grid place-items-center w-10 h-10 rounded-full shrink-0 {{ $call->direction === 'inbound' ? 'bg-voice-100 text-voice-700' : 'bg-brand-100 text-brand-700' }}">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/></svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="font-semibold text-gray-900 truncate">{{ $call->contact?->name ?? $call->from_phone ?? $call->to_phone ?? __('Unknown caller') }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ ucfirst($call->direction) }}
                                            @if($call->placedBy) · {{ $call->placedBy->name }} @endif
                                            · {{ $call->started_at?->diffForHumans() ?? $call->created_at->diffForHumans() }}
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold {{ $connected ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $connected ? 'bg-brand' : 'bg-amber-500 animate-pulse' }}"></span>
                                        {{ ucfirst($call->status) }}
                                    </span>
                                </li>
                            @empty
                                <li class="py-8 text-center text-sm text-gray-500">{{ __('No calls in progress.') }}</li>
                            @endforelse
                        </ul>

                        @if($team->isNotEmpty())
                            <div class="pt-1">
                                <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Team') }}</h3>
                                <ul class="flex flex-col gap-2">
                                    @foreach($team as $member)
                                        <li class="flex items-center gap-3 text-sm">
                                            <span class="w-2 h-2 rounded-full {{ $member['online'] ? 'bg-brand' : 'bg-gray-300' }}"></span>
                                            <span class="flex-1 min-w-0 truncate font-medium text-gray-900">{{ $member['name'] }}</span>
                                            <span class="text-xs text-gray-500">{{ $member['online'] ? ucfirst($member['presence']) : __('Offline') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endif

                <div class="flex-[2_1_560px] min-w-0 flex flex-col gap-5">

                    {{-- KPI strip: one card, four measures --}}
                    <section aria-label="{{ __('Today in numbers') }}" class="flex flex-wrap bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                        <div class="flex-1 basis-[130px] min-w-0 p-5">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Messages today') }}</div>
                            <div class="mt-1 font-display text-3xl xl:text-4xl font-bold tracking-tight text-gray-900 tabular-nums">{{ number_format($messagesToday) }}</div>
                            <div class="mt-1.5 flex items-center gap-2 text-xs">
                                @if($messagesDelta !== null)
                                    <span class="px-2 py-0.5 rounded-full font-bold {{ $messagesDelta >= 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $messagesDelta >= 0 ? '+' : '−' }}{{ abs($messagesDelta) }}%</span>
                                    <span class="text-gray-500">{{ __('vs yesterday') }}</span>
                                @else
                                    <span class="text-gray-500">{{ __('No messages yesterday to compare') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex-1 basis-[130px] min-w-0 p-5 border-l border-gray-200">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Delivery rate') }}</div>
                            <div class="mt-1 font-display text-4xl font-bold tracking-tight text-gray-900 tabular-nums">{{ $deliveryRate }}%</div>
                            <div class="mt-1.5 text-xs text-gray-500">{{ __('Average across campaigns') }}</div>
                        </div>
                        <div class="flex-1 basis-[130px] min-w-0 p-5 border-l border-gray-200">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Campaigns') }}</div>
                            <div class="mt-1 font-display text-4xl font-bold tracking-tight text-gray-900 tabular-nums">{{ number_format($totalCampaigns) }}</div>
                            <div class="mt-1.5 text-xs text-gray-500">{{ trans_choice(':count sending now|:count sending now', $sendingCampaigns) }}</div>
                        </div>
                        <div class="flex-1 basis-[130px] min-w-0 p-5 border-l border-gray-200">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500">{{ __('Contacts') }}</div>
                            <div class="mt-1 font-display text-4xl font-bold tracking-tight text-gray-900 tabular-nums">{{ number_format($totalContacts) }}</div>
                            <div class="mt-1.5 text-xs text-gray-500">{{ __('In your audience') }}</div>
                        </div>
                    </section>

                    <div class="flex flex-wrap gap-5 flex-1">
                        {{-- Recent campaigns with progress --}}
                        <section aria-labelledby="camp-h" class="flex-[1.7_1_360px] min-w-0 bg-white border border-gray-200 rounded-2xl shadow-sm p-5">
                            <div class="flex items-center mb-3">
                                <h2 id="camp-h" class="font-display text-xl font-bold tracking-tight text-gray-900">{{ __('Recent campaigns') }}</h2>
                                @can('campaigns.view')
                                    <a href="{{ route('campaigns.index') }}" wire:navigate class="ml-auto -my-2 py-2 text-sm font-semibold text-brand-text hover:underline">{{ __('All campaigns') }} →</a>
                                @endcan
                            </div>
                            <ul>
                                @forelse($recentCampaigns as $campaign)
                                    @php
                                        $total = (int) ($campaign->total_contacts ?? 0);
                                        $sent = (int) ($campaign->sent_count ?? 0);
                                        $pct = $total > 0 ? min(100, (int) round($sent / $total * 100)) : 0;
                                    @endphp
                                    <li class="py-3.5 border-t border-gray-200 first:border-t-0">
                                        <div class="flex items-center gap-2.5 flex-wrap">
                                            <span class="font-semibold text-gray-900">{{ $campaign->name }}</span>
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $statusBadge((string) $campaign->status) }}">{{ $campaign->status }}</span>
                                            <span class="ml-auto font-data text-xs text-gray-500">{{ number_format($sent) }} / {{ number_format($total) }}</span>
                                        </div>
                                        <div class="mt-2.5 h-2 rounded-full bg-gray-200 overflow-hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $campaign->name }}">
                                            <div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </li>
                                @empty
                                    <li class="py-10 text-center text-gray-500">
                                        <p class="font-medium">{{ __('No campaigns yet') }}</p>
                                        <p class="text-sm mt-1">{{ __('Create your first campaign to get started.') }}</p>
                                    </li>
                                @endforelse
                            </ul>
                        </section>

                        {{-- Needs you --}}
                        <section aria-labelledby="need-h" class="flex-[1_1_260px] min-w-0 bg-white border border-gray-200 rounded-2xl shadow-sm p-5">
                            <h2 id="need-h" class="font-display text-xl font-bold tracking-tight text-gray-900 mb-3">{{ __('Needs you') }}</h2>
                            @php
                                $rows = [];
                                if ($canSeeCalls) {
                                    $rows[] = ['count' => $missedCalls, 'text' => __('Missed calls, last 24 h'), 'tone' => 'red', 'href' => route('calls.index'), 'action' => __('Review')];
                                }
                                if (auth()->user()->can('conversations.view_all')) {
                                    $rows[] = ['count' => $unassignedChats, 'text' => __('Unassigned chats'), 'tone' => 'amber', 'href' => route('conversations.index'), 'action' => __('Assign')];
                                }
                                $rows[] = ['count' => $failedToday, 'text' => __('Failed sends today'), 'tone' => 'red', 'href' => auth()->user()->can('campaigns.view') ? route('campaigns.index') : null, 'action' => __('Check')];
                                $tones = ['red' => 'bg-red-100 text-red-700', 'amber' => 'bg-yellow-100 text-yellow-700', 'clear' => 'bg-gray-100 text-gray-500'];
                            @endphp
                            <ul class="flex flex-col gap-2.5">
                                @foreach($rows as $row)
                                    <li class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
                                        <span class="grid place-items-center min-w-[34px] h-[34px] px-1 rounded-lg font-display text-base font-bold tabular-nums {{ $row['count'] > 0 ? $tones[$row['tone']] : $tones['clear'] }}">{{ $row['count'] }}</span>
                                        <span class="flex-1 min-w-0 text-[13.5px] font-medium leading-snug text-gray-800">{{ $row['text'] }}</span>
                                        @if($row['count'] > 0 && $row['href'])
                                            <a href="{{ $row['href'] }}" wire:navigate class="text-sm font-bold text-gray-900 hover:underline whitespace-nowrap">{{ $row['action'] }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    </div>
                </div>
            </div>

            {{-- Charts --}}
            <div class="flex flex-wrap gap-5">
                <section aria-labelledby="vol-h" class="flex-[2_1_520px] min-w-0 bg-white border border-gray-200 rounded-2xl shadow-sm p-5">
                    <div class="flex items-baseline gap-3 mb-4">
                        <h2 id="vol-h" class="font-display text-xl font-bold tracking-tight text-gray-900">{{ __('Message volume') }}</h2>
                        <span class="text-sm text-gray-500">{{ __('Last 30 days') }}</span>
                    </div>
                    <div class="relative" style="height: 280px;">
                        @if($messagesPerDay->isEmpty())
                            <div class="absolute inset-0 grid place-items-center text-sm text-gray-500">{{ __('No messages sent in the last 30 days yet.') }}</div>
                        @else
                            <canvas id="messagesPerDayChart" data-series="{{ json_encode($messagesPerDay) }}"
                                    role="img" aria-label="{{ __('Line chart of messages sent per day over the last 30 days') }}"></canvas>
                        @endif
                    </div>
                </section>

                <section aria-labelledby="status-h" class="flex-[1_1_300px] min-w-0 bg-white border border-gray-200 rounded-2xl shadow-sm p-5">
                    <h2 id="status-h" class="font-display text-xl font-bold tracking-tight text-gray-900 mb-4">{{ __('Delivery status') }}</h2>
                    <div class="relative" style="height: 280px;">
                        @if($statusBreakdown->isEmpty())
                            <div class="absolute inset-0 grid place-items-center text-sm text-gray-500">{{ __('No message status data yet.') }}</div>
                        @else
                            <canvas id="statusBreakdownChart" data-series="{{ json_encode($statusBreakdown) }}"
                                    role="img" aria-label="{{ __('Doughnut chart of message delivery status breakdown') }}"></canvas>
                        @endif
                    </div>
                </section>
            </div>

        </div>
    </div>

    @push('scripts')
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        // Chart colours come from the active theme's CSS variables, so the charts follow
        // light/dark and are redrawn when the theme is toggled.
        function bqToken(name, alpha) {
            const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return alpha === undefined ? `rgb(${raw})` : `rgb(${raw} / ${alpha})`;
        }

        function bqDrawDashboardCharts() {
            if (typeof Chart === 'undefined') return;

            const tooltip = {
                backgroundColor: bqToken('--gray-900'),
                titleColor: bqToken('--gray-50'),
                bodyColor: bqToken('--gray-50'),
                padding: 12,
                cornerRadius: 10,
            };

            const lineEl = document.getElementById('messagesPerDayChart');
            if (lineEl) {
                Chart.getChart(lineEl)?.destroy();
                const messagesData = JSON.parse(lineEl.dataset.series || '[]');
                const labels = messagesData.map(item => new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));

                new Chart(lineEl, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Messages sent',
                            data: messagesData.map(item => item.count),
                            borderColor: bqToken('--brand'),
                            backgroundColor: bqToken('--brand', 0.14),
                            pointBackgroundColor: bqToken('--brand'),
                            fill: true,
                            tension: 0.4,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            borderWidth: 2.5,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: bqToken('--gray-500'), maxTicksLimit: 10, font: { size: 11 } } },
                            y: { beginAtZero: true, grid: { color: bqToken('--gray-200') }, ticks: { color: bqToken('--gray-500'), font: { size: 11 }, precision: 0 } },
                        },
                    },
                });
            }

            const statusEl = document.getElementById('statusBreakdownChart');
            if (statusEl) {
                Chart.getChart(statusEl)?.destroy();
                const statusData = JSON.parse(statusEl.dataset.series || '[]');
                const colors = {
                    SENT: bqToken('--gray-400'),
                    DELIVERED: bqToken('--voice'),
                    READ: bqToken('--brand'),
                    FAILED: bqToken('--danger'),
                };

                new Chart(statusEl, {
                    type: 'doughnut',
                    data: {
                        labels: statusData.map(item => item.status),
                        datasets: [{
                            data: statusData.map(item => item.count),
                            backgroundColor: statusData.map(item => colors[item.status] || bqToken('--gray-400')),
                            borderColor: bqToken('--surface'),
                            borderWidth: 3,
                            hoverOffset: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '66%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { usePointStyle: true, pointStyle: 'circle', padding: 18, color: bqToken('--gray-700'), font: { size: 12 } },
                            },
                            tooltip,
                        },
                    },
                });
            }
        }

        // Draw on initial load (the canvases exist — this script is at end of
        // body) and on every SPA navigation. wire:navigate doesn't fire
        // DOMContentLoaded, and the redraw reads fresh per-page data from the
        // canvas data-series, so navigating back to the dashboard is correct.
        bqDrawDashboardCharts();
        if (! window.__bqDashboardChartsBound) {
            window.__bqDashboardChartsBound = true;
            document.addEventListener('livewire:navigated', bqDrawDashboardCharts);
            // Redraw with the other theme's colours when the user toggles light/dark.
            window.addEventListener('bq-theme-changed', bqDrawDashboardCharts);
        }
    </script>
    @endpush
</x-app-layout>
