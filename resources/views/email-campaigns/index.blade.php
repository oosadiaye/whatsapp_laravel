<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Email Campaigns') }}</h2>
            <div class="flex items-center gap-2">
            <a href="{{ route('email-suppressions.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">{{ __('Suppression list') }}</a>
            @can('email.create')
                <a href="{{ route('email-campaigns.create') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-[#4f46e5] text-white rounded-lg text-sm font-semibold hover:bg-[#4338ca] transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    {{ __('New Email Campaign') }}
                </a>
            @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        @if(session('success'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        @can('email.create')
            @if(! empty($templates ?? []))
                <div class="mb-8">
                    <h3 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Start from a beautiful template') }}</h3>
                    <p class="text-xs text-gray-400 mb-3">{{ __('Pick a design to open the composer pre-filled — then make it yours.') }}</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        @foreach($templates as $tpl)
                            <a href="{{ route('email-campaigns.create', ['template' => $tpl['key']]) }}"
                               class="group rounded-xl border border-gray-200 bg-white overflow-hidden hover:border-gray-300 hover:shadow-md transition focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                <span class="block h-16 relative" style="background: {{ $tpl['accent'] }}">
                                    <span class="absolute inset-0 grid place-items-center text-white/90 text-2xl font-black tracking-tight">{{ mb_substr($tpl['name'], 0, 1) }}</span>
                                </span>
                                <span class="block px-3 py-2.5">
                                    <span class="block text-sm font-semibold text-gray-800">{{ $tpl['name'] }}</span>
                                    <span class="block text-[11px] text-gray-400 mt-0.5 leading-snug">{{ $tpl['description'] }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endcan

        @php
            $statusMeta = [
                'draft' => ['bg-gray-100 text-gray-600', 'Draft'],
                'scheduled' => ['bg-indigo-100 text-indigo-700', 'Scheduled'],
                'queued' => ['bg-amber-100 text-amber-700', 'Queued'],
                'sending' => ['bg-amber-100 text-amber-700 animate-pulse', 'Sending'],
                'sent' => ['bg-emerald-100 text-emerald-700', 'Sent'],
                'failed' => ['bg-red-100 text-red-700', 'Failed'],
                'paused' => ['bg-amber-100 text-amber-700', 'Paused'],
                'cancelled' => ['bg-gray-100 text-gray-500', 'Cancelled'],
            ];
        @endphp

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="divide-y divide-gray-50">
                @forelse($campaigns as $campaign)
                    @php $meta = $statusMeta[$campaign->status] ?? ['bg-gray-100 text-gray-600', ucfirst($campaign->status)]; @endphp
                    <a href="{{ route('email-campaigns.show', $campaign) }}" class="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition">
                        <span class="grid place-items-center w-10 h-10 rounded-lg bg-indigo-50 text-indigo-500 shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-sm text-gray-900 truncate">{{ $campaign->name }}</div>
                            <div class="text-xs text-gray-400 truncate">{{ $campaign->subject }}</div>
                        </div>
                        @if($campaign->status === 'scheduled' && $campaign->scheduled_at)
                            <span class="text-xs text-indigo-500 whitespace-nowrap">{{ $campaign->scheduled_at->format('M j, g:i A') }}{{ $campaign->isRecurring() ? ' · '.$campaign->recurrence : '' }}</span>
                        @elseif(in_array($campaign->status, ['sent','sending']))
                            <span class="text-xs text-gray-400 whitespace-nowrap">{{ $campaign->sent_count }}/{{ $campaign->total_recipients }} sent</span>
                        @endif
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold {{ $meta[0] }}">{{ $meta[1] }}</span>
                    </a>
                @empty
                    <div class="px-5 py-16 text-center text-sm text-gray-400">
                        {{ __('No email campaigns yet.') }}
                        @can('email.create')<a href="{{ route('email-campaigns.create') }}" class="text-indigo-600 font-semibold hover:underline">{{ __('Create one') }}</a>.@endcan
                    </div>
                @endforelse
            </div>
            @if($campaigns->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">{{ $campaigns->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
