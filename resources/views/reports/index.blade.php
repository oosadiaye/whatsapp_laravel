<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Reports & Health') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- AT Health --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Africa\'s Talking Health') }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Status') }}</p>
                        <span class="inline-flex items-center gap-1.5 mt-1 text-sm font-medium {{ $atHealth['configured'] ? 'text-green-700' : 'text-red-700' }}">
                            <span class="w-2 h-2 rounded-full {{ $atHealth['configured'] ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            {{ $atHealth['configured'] ? __('Configured') : __('Not configured') }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Username') }}</p>
                        <p class="text-sm font-data text-gray-900 mt-1">{{ $atHealth['username'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Virtual Number') }}</p>
                        <p class="text-sm font-data text-gray-900 mt-1">{{ $atHealth['virtual_number'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('24h Calls') }}</p>
                        <p class="text-sm text-gray-900 mt-1">
                            @if($atHealth['recent_success']) <span class="text-green-600">{{ __('Success') }}</span> @endif
                            @if($atHealth['recent_failure']) <span class="text-red-600 ml-2">{{ __('Failures') }}</span> @endif
                            @if(!$atHealth['recent_success'] && !$atHealth['recent_failure']) <span class="text-gray-400">{{ __('No calls') }}</span> @endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- Call Stats --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Call Statistics') }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Total Calls') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $callStats['total'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Today') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $callStats['today'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Missed') }}</p>
                        <p class="text-2xl font-bold text-red-600 mt-1">{{ $callStats['missed'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Total Duration') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1 font-data">{{ sprintf('%dh %dm', intdiv($callStats['total_duration'], 3600), intdiv($callStats['total_duration'] % 3600, 60)) }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Total Cost') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($callStats['total_cost'] / 100, 2) }} {{ session('currency', 'KES') }}</p>
                    </div>
                </div>
                <div class="mt-4">
                    @livewire('call-cost-dashboard')
                </div>
            </div>

            {{-- Email Stats --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Email Accounts') }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Total') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $emailStats['accounts'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Active') }}</p>
                        <p class="text-2xl font-bold text-green-600 mt-1">{{ $emailStats['active_accounts'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Campaigns') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $emailStats['campaigns'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Sequences') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $emailStats['sequences'] }}</p>
                    </div>
                </div>
            </div>

            {{-- Agent Stats --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Agent Status') }}</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Total Agents') }}</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ $agentStats['total'] }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">{{ __('Online Now') }}</p>
                        <p class="text-2xl font-bold text-green-600 mt-1">{{ $agentStats['online'] }}</p>
                    </div>
                </div>
            </div>

            {{-- Recent Audit --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-4">{{ __('Recent Activity') }}</h3>
                @if($recentAudits->isEmpty())
                    <p class="text-sm text-gray-400">{{ __('No recent activity logged.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase tracking-wide">
                                    <th class="pb-2 font-semibold">{{ __('Time') }}</th>
                                    <th class="pb-2 font-semibold">{{ __('User') }}</th>
                                    <th class="pb-2 font-semibold">{{ __('Action') }}</th>
                                    <th class="pb-2 font-semibold">{{ __('Resource') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($recentAudits as $log)
                                    <tr>
                                        <td class="py-2 text-gray-500 font-data text-xs">{{ $log->created_at->diffForHumans() }}</td>
                                        <td class="py-2 text-gray-700">{{ $log->user?->name ?? __('System') }}</td>
                                        <td class="py-2"><code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">{{ $log->action }}</code></td>
                                        <td class="py-2 text-gray-500 text-xs">{{ $log->resource_type ? $log->resource_type.' #'.$log->resource_id : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
