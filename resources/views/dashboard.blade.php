<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- Stat Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                {{-- Total Campaigns --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-[#25D366]/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-[#25D366]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Campaigns</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($totalCampaigns) }}</p>
                    </div>
                </div>

                {{-- Total Contacts --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-[#25D366]/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-[#25D366]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Contacts</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($totalContacts) }}</p>
                    </div>
                </div>

                {{-- Sent Today --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-[#25D366]/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-[#25D366]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Sent Today</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($messagesToday) }}</p>
                    </div>
                </div>

                {{-- Delivery Rate --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-[#25D366]/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-[#25D366]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Delivery Rate</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($deliveryRate, 1) }}%</p>
                    </div>
                </div>
            </div>

            {{-- Charts Section --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Messages Per Day Line Chart --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Messages Sent (Last 30 Days)</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="messagesPerDayChart"></canvas>
                    </div>
                </div>

                {{-- Status Breakdown Doughnut Chart --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Message Status Breakdown</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="statusBreakdownChart"></canvas>
                    </div>
                </div>
            </div>

            {{-- Recent Campaigns Table --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Recent Campaigns</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                            <tr>
                                <th class="px-6 py-3 font-medium">Name</th>
                                <th class="px-6 py-3 font-medium">Status</th>
                                <th class="px-6 py-3 font-medium text-right">Sent</th>
                                <th class="px-6 py-3 font-medium text-right">Delivered</th>
                                <th class="px-6 py-3 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($recentCampaigns as $campaign)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-gray-900">{{ $campaign->name }}</td>
                                    <td class="px-6 py-4">
                                        @php
                                            $badgeClasses = match($campaign->status) {
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
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                                            {{ $campaign->status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right text-gray-600">{{ number_format($campaign->sent_count ?? 0) }}</td>
                                    <td class="px-6 py-4 text-right text-gray-600">{{ number_format($campaign->delivered_count ?? 0) }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ $campaign->created_at->format('M d, Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                                        </svg>
                                        <p class="font-medium">No campaigns yet</p>
                                        <p class="text-sm mt-1">Create your first campaign to get started.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Messages Per Day - Line Chart
            const messagesData = @json($messagesPerDay);
            const messagesLabels = messagesData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });
            const messagesCounts = messagesData.map(item => item.count);

            new Chart(document.getElementById('messagesPerDayChart'), {
                type: 'line',
                data: {
                    labels: messagesLabels,
                    datasets: [{
                        label: 'Messages Sent',
                        data: messagesCounts,
                        borderColor: '#25D366',
                        backgroundColor: 'rgba(37, 211, 102, 0.08)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#25D366',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#f9fafb',
                            bodyColor: '#f9fafb',
                            padding: 12,
                            cornerRadius: 8,
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: '#9ca3af',
                                maxTicksLimit: 10,
                                font: { size: 11 },
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f3f4f6' },
                            ticks: {
                                color: '#9ca3af',
                                font: { size: 11 },
                                precision: 0,
                            }
                        }
                    }
                }
            });

            // Status Breakdown - Doughnut Chart
            const statusData = @json($statusBreakdown);
            const statusColorMap = {
                'SENT': '#3B82F6',
                'DELIVERED': '#25D366',
                'READ': '#8B5CF6',
                'FAILED': '#EF4444',
            };
            const statusLabels = statusData.map(item => item.status);
            const statusCounts = statusData.map(item => item.count);
            const statusColors = statusData.map(item => statusColorMap[item.status] || '#9ca3af');

            new Chart(document.getElementById('statusBreakdownChart'), {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: statusColors,
                        borderWidth: 0,
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 20,
                                color: '#374151',
                                font: { size: 12 },
                            }
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#f9fafb',
                            bodyColor: '#f9fafb',
                            padding: 12,
                            cornerRadius: 8,
                        }
                    }
                }
            });
        });
    </script>
    @endpush
</x-app-layout>
