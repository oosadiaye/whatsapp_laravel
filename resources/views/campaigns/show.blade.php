<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $campaign->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">Created {{ $campaign->created_at->diffForHumans() }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($campaign->status === 'DRAFT')
                    <form action="{{ route('campaigns.launch', $campaign) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">Launch Campaign</button>
                    </form>
                @endif

                {{-- Edit available pre-launch (DRAFT, QUEUED) and when paused mid-flight.
                     Hidden during RUNNING because workers are actively reading the campaign
                     config to send messages — mid-flight edits would race with sends. Hidden
                     in terminal states (COMPLETED / FAILED / CANCELLED) — use Clone instead.
                     Permission-gated so users without campaigns.edit don't see a 403 trap. --}}
                @can('campaigns.edit')
                    @if(in_array($campaign->status, ['DRAFT', 'QUEUED', 'PAUSED'], true))
                        <a href="{{ route('campaigns.edit', $campaign) }}"
                           class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                           title="{{ $campaign->status === 'PAUSED' ? __('Edit paused campaign — resume after saving') : __('Edit campaign') }}">
                            {{ __('Edit') }}
                        </a>
                    @endif
                @endcan
                @if($campaign->status === 'RUNNING')
                    <form action="{{ route('campaigns.pause', $campaign) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-orange-500 px-4 py-2 text-sm font-medium text-white hover:bg-orange-600">Pause</button>
                    </form>
                    <form action="{{ route('campaigns.cancel', $campaign) }}" method="POST" class="inline" onsubmit="return confirm('Cancel this campaign?')">
                        @csrf
                        <button type="submit" class="rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white hover:bg-red-600">Cancel</button>
                    </form>
                @endif
                @if($campaign->status === 'PAUSED')
                    <form action="{{ route('campaigns.resume', $campaign) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white hover:bg-blue-600">Resume</button>
                    </form>
                @endif
                @if(in_array($campaign->status, ['COMPLETED', 'FAILED', 'CANCELLED']))
                    <form action="{{ route('campaigns.clone', $campaign) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Clone Campaign</button>
                    </form>
                @endif
                <a href="{{ route('campaigns.exportLogs', $campaign) }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Export CSV</a>

                {{-- Delete: hidden on RUNNING (workers active mid-flight); visible on every
                     other state including PAUSED, COMPLETED, FAILED, CANCELLED. Server also
                     re-checks via DELETABLE_STATUSES so direct DELETE requests are blocked. --}}
                @can('campaigns.delete')
                    @if(in_array($campaign->status, ['DRAFT', 'QUEUED', 'PAUSED', 'COMPLETED', 'FAILED', 'CANCELLED'], true))
                        <form action="{{ route('campaigns.destroy', $campaign) }}" method="POST" class="inline"
                              onsubmit="return confirm('{{ __("Permanently delete this campaign and all its message logs? This cannot be undone.") }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Delete') }}</button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
            {{-- Live Stats --}}
            <livewire:campaign-status :campaignId="$campaign->id" />

            {{-- Campaign Details --}}
            <div class="rounded-xl bg-white p-6 shadow-sm">
                <h3 class="text-lg font-medium text-gray-900">Campaign Details</h3>
                <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Instance:</span> <span class="font-medium">{{ $campaign->whatsAppInstance?->display_name ?? $campaign->whatsAppInstance?->instance_name ?? 'N/A' }}</span></div>
                    <div><span class="text-gray-500">Rate:</span> <span class="font-medium">{{ $campaign->rate_per_minute }} msgs/min</span></div>
                    <div><span class="text-gray-500">Delay:</span> <span class="font-medium">{{ $campaign->delay_min }}-{{ $campaign->delay_max }}s jitter</span></div>
                    <div><span class="text-gray-500">Scheduled:</span> <span class="font-medium">{{ $campaign->scheduled_at?->format('M d, Y H:i') ?? 'Immediate' }}</span></div>
                </div>
                <div class="mt-4">
                    <span class="text-sm text-gray-500">Message:</span>
                    <div class="mt-1 rounded-lg bg-gray-50 p-3 text-sm text-gray-800 whitespace-pre-wrap">{{ $campaign->message }}</div>
                </div>
            </div>

            {{-- Message Logs --}}
            <div class="rounded-xl bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Message Logs</h3>
                <livewire:message-logs-table :campaignId="$campaign->id" />
            </div>
        </div>
    </div>
</x-app-layout>
