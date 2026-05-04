<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Campaigns</h2>
            <a href="{{ route('campaigns.create') }}" class="inline-flex items-center rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Campaign
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Sent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Delivered</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Failed</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Scheduled</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse($campaigns as $campaign)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-6 py-4">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-gray-900 hover:text-[#25D366]">{{ $campaign->name }}</a>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                @php
                                    $colors = [
                                        'DRAFT' => 'bg-gray-100 text-gray-700',
                                        'QUEUED' => 'bg-yellow-100 text-yellow-700',
                                        'RUNNING' => 'bg-blue-100 text-blue-700',
                                        'PAUSED' => 'bg-orange-100 text-orange-700',
                                        'COMPLETED' => 'bg-green-100 text-green-700',
                                        'FAILED' => 'bg-red-100 text-red-700',
                                        'CANCELLED' => 'bg-gray-100 text-gray-500',
                                    ];
                                @endphp
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors[$campaign->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ $campaign->status }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $campaign->sent_count }}/{{ $campaign->total_contacts }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $campaign->delivered_count }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $campaign->failed_count }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $campaign->scheduled_at?->format('M d, Y H:i') ?? '-' }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="text-[#25D366] hover:underline">View</a>
                                @can('campaigns.edit')
                                    @if(in_array($campaign->status, ['DRAFT', 'QUEUED', 'PAUSED'], true))
                                        <a href="{{ route('campaigns.edit', $campaign) }}" class="ml-3 text-blue-600 hover:underline">Edit</a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">No campaigns yet. Create your first campaign!</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($campaigns->hasPages())
            <div class="mt-4">{{ $campaigns->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
