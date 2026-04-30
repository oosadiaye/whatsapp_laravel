<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Calls') }}</h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">

        {{-- Filter chips --}}
        <div class="bg-white shadow-sm rounded-lg p-4 mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('calls.index') }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ ! $currentDirection && ! $currentStatus
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('All calls') }}
            </a>
            <a href="{{ route('calls.index', ['direction' => 'inbound']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentDirection === 'inbound'
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Inbound') }}
            </a>
            <a href="{{ route('calls.index', ['direction' => 'outbound']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentDirection === 'outbound'
                         ? 'bg-emerald-100 text-emerald-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Outbound') }}
            </a>
            <span class="text-gray-300">|</span>
            <a href="{{ route('calls.index', ['status' => 'missed']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentStatus === 'missed'
                         ? 'bg-amber-100 text-amber-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Missed') }}
            </a>
            <a href="{{ route('calls.index', ['status' => 'failed']) }}"
               class="px-3 py-1 rounded-full text-sm
                      {{ $currentStatus === 'failed'
                         ? 'bg-red-100 text-red-800 font-semibold'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ __('Failed') }}
            </a>
        </div>

        {{-- Calls list --}}
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('When') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Direction') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Contact') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Duration') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Instance') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($calls as $call)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-700 whitespace-nowrap">
                                {{ $call->created_at->format('M d, H:i') }}
                            </td>
                            <td class="px-6 py-3 text-sm">
                                @if($call->isInbound())
                                    <span class="inline-flex items-center gap-1 text-emerald-700">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        Inbound
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-blue-700">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                        Outbound
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <div class="font-medium text-gray-900">{{ $call->contact->name ?? $call->from_phone }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $call->isInbound() ? $call->from_phone : $call->to_phone }}</div>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                @php
                                    $statusClass = match($call->status) {
                                        'connected', 'ended' => 'bg-emerald-100 text-emerald-800',
                                        'missed' => 'bg-amber-100 text-amber-800',
                                        'declined', 'failed' => 'bg-red-100 text-red-800',
                                        default => 'bg-blue-100 text-blue-800',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                    {{ ucfirst($call->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700 whitespace-nowrap">
                                {{ $call->duration_seconds ? gmdate('i:s', $call->duration_seconds) : '—' }}
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-600">
                                {{ $call->whatsappInstance->display_name ?? $call->whatsappInstance->instance_name }}
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a href="{{ route('conversations.show', $call->conversation_id) }}"
                                   class="text-sm font-medium text-emerald-700 hover:text-emerald-900">
                                    Open conversation →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                </svg>
                                <p>{{ __('No calls match this filter.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($calls->hasPages())
                <div class="px-6 py-3 border-t border-gray-100">{{ $calls->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
