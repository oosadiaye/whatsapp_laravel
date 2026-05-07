<div wire:poll.10s class="bg-white shadow-sm rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Name') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Presence') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active load') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Last seen') }}</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
            @forelse($agents as $agent)
                <tr>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">{{ $agent->name }}</div>
                        <div class="text-xs text-gray-500">{{ $agent->email }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center gap-2 text-sm">
                            <span class="w-2.5 h-2.5 rounded-full
                                @if($agent->presence_status === \App\Models\User::PRESENCE_AVAILABLE) bg-green-500
                                @elseif($agent->presence_status === \App\Models\User::PRESENCE_BUSY) bg-orange-500
                                @else bg-gray-400 @endif"></span>
                            <span class="text-gray-700">{{ ucfirst($agent->presence_status) }}</span>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="{{ $agent->active_count >= $cap ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                            {{ $agent->active_count }} / {{ $cap }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if($agent->last_seen_at && $agent->last_seen_at >= $availabilityCutoff)
                            <span class="text-green-700 font-medium">online</span>
                        @elseif($agent->last_seen_at)
                            <span class="text-gray-600">{{ $agent->last_seen_at->diffForHumans() }}</span>
                        @else
                            <span class="text-gray-400 italic">never</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center text-gray-400">
                        {{ __('No agents on this team') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
