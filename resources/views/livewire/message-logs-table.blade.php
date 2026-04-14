<div class="space-y-4" wire:poll.5s>
    {{-- Filter Bar --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <div class="sm:w-48">
            <select wire:model.live="filterStatus"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                <option value="">All Statuses</option>
                <option value="PENDING">PENDING</option>
                <option value="SENT">SENT</option>
                <option value="DELIVERED">DELIVERED</option>
                <option value="READ">READ</option>
                <option value="FAILED">FAILED</option>
            </select>
        </div>
        <div class="flex-1">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>
                <input wire:model.live.debounce.300ms="search"
                       type="text"
                       placeholder="Search by phone number..."
                       class="w-full pl-10 rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                    <tr>
                        <th class="px-6 py-3 font-medium">Phone</th>
                        <th class="px-6 py-3 font-medium">Contact Name</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                        <th class="px-6 py-3 font-medium">Sent At</th>
                        <th class="px-6 py-3 font-medium">Delivered At</th>
                        <th class="px-6 py-3 font-medium">Read At</th>
                        <th class="px-6 py-3 font-medium">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($logs as $log)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-3 font-mono text-gray-900">{{ $log->phone }}</td>
                            <td class="px-6 py-3 text-gray-700">{{ $log->contact_name ?? '-' }}</td>
                            <td class="px-6 py-3">
                                @php
                                    $logBadge = match($log->status) {
                                        'PENDING' => 'bg-gray-100 text-gray-600',
                                        'SENT' => 'bg-blue-100 text-blue-700',
                                        'DELIVERED' => 'bg-green-100 text-green-700',
                                        'READ' => 'bg-purple-100 text-purple-700',
                                        'FAILED' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $logBadge }}">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-gray-500 whitespace-nowrap">{{ $log->sent_at ? \Carbon\Carbon::parse($log->sent_at)->format('M d, H:i') : '-' }}</td>
                            <td class="px-6 py-3 text-gray-500 whitespace-nowrap">{{ $log->delivered_at ? \Carbon\Carbon::parse($log->delivered_at)->format('M d, H:i') : '-' }}</td>
                            <td class="px-6 py-3 text-gray-500 whitespace-nowrap">{{ $log->read_at ? \Carbon\Carbon::parse($log->read_at)->format('M d, H:i') : '-' }}</td>
                            <td class="px-6 py-3 text-red-500 text-xs max-w-xs truncate" title="{{ $log->error_message ?? '' }}">
                                {{ $log->error_message ?? '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                <svg class="w-10 h-10 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>
                                </svg>
                                <p class="font-medium">No message logs found</p>
                                <p class="text-sm mt-1">Logs will appear here once messages are sent.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($logs->hasPages())
            <div class="px-6 py-4 border-t border-gray-100">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
