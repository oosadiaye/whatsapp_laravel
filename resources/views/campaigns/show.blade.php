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
                @endif

                {{-- Cancel: works on RUNNING (mid-flight stop) AND QUEUED/PAUSED (clear queue
                     before any sends happen). The service-layer cancel() also marks every
                     PENDING MessageLog as CANCELLED and best-effort deletes orphan jobs from
                     the database queue table, so jobs queued before cancel won't fire. --}}
                @if(in_array($campaign->status, ['RUNNING', 'QUEUED', 'PAUSED'], true))
                    <form action="{{ route('campaigns.cancel', $campaign) }}" method="POST" class="inline"
                          onsubmit="return confirm('{{ __('Cancel this campaign and clear its pending sends?') }}')">
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
            {{-- Stuck-queue warning: if a campaign has been QUEUED for > 5 minutes
                 with total_contacts still 0, the queue worker likely isn't running.
                 Same for RUNNING campaigns where started_at is old but sent_count
                 is still 0 — workers picked up the batch dispatch but stalled
                 before fanning out the actual sends. --}}
            @php
                $stuckQueued = $campaign->status === 'QUEUED'
                    && $campaign->started_at
                    && $campaign->started_at->lt(now()->subMinutes(5))
                    && $campaign->total_contacts === 0;
                $stuckRunning = $campaign->status === 'RUNNING'
                    && $campaign->started_at
                    && $campaign->started_at->lt(now()->subMinutes(5))
                    && $campaign->total_contacts > 0
                    && $campaign->sent_count === 0
                    && $campaign->failed_count === 0;
            @endphp
            @if($stuckQueued || $stuckRunning)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <div>
                            <p class="font-semibold">{{ __('Queue worker may not be running') }}</p>
                            <p class="mt-1 text-amber-800">
                                @if($stuckQueued)
                                    {{ __('This campaign has been queued for over 5 minutes without any contacts being processed.') }}
                                @else
                                    {{ __('This campaign started over 5 minutes ago but no messages have been sent yet.') }}
                                @endif
                                {{ __('On the server, run:') }}
                                <code class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs">php artisan queue:work --queue=default,messages</code>
                                {{ __('or check Horizon / supervisord status.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

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

            {{-- Recipients — confirms which contact groups are attached and their active-
                 contact counts. This was the missing piece causing the 'contacts aren't
                 adding' confusion: data WAS attached correctly via campaign_group pivot,
                 but the show page never displayed it. Sum of active_contacts_count across
                 groups is the BEFORE-LAUNCH estimate of how many sends will fan out.
                 After launch, CampaignBatchDispatch deduplicates across groups (same
                 contact in two groups counts once) and writes the de-duped count to
                 campaign.total_contacts. --}}
            <div class="rounded-xl bg-white p-6 shadow-sm">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Recipients') }}</h3>
                @if($campaign->contactGroups->isEmpty())
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-medium">{{ __('No recipients attached to this campaign.') }}</p>
                        <p class="mt-1 text-amber-800">
                            {{ __('Edit the campaign and pick at least one contact group on the Recipients tab — without recipients, no messages will be sent.') }}
                        </p>
                    </div>
                @else
                    @php
                        // Sum the active-contact counts for the pre-launch estimate.
                        // Note: this counts duplicates if the same contact is in two
                        // groups; the actual launch de-duplicates, so total_contacts
                        // after launch may be smaller. We surface that in the label.
                        $estimatedReach = $campaign->contactGroups->sum('active_contacts_count');
                    @endphp
                    <div class="mt-4 grid grid-cols-3 gap-4 text-sm">
                        <div><span class="text-gray-500">{{ __('Groups attached') }}:</span> <span class="font-medium">{{ $campaign->contactGroups->count() }}</span></div>
                        <div>
                            <span class="text-gray-500">{{ __('Estimated reach') }}:</span>
                            <span class="font-medium">{{ $estimatedReach }} {{ __('contacts') }}</span>
                            @if($campaign->contactGroups->count() > 1)
                                <span class="ml-1 text-xs text-gray-400" title="{{ __('Sum across groups; de-duplicated at launch') }}">·</span>
                            @endif
                        </div>
                        @if($campaign->total_contacts > 0)
                            <div><span class="text-gray-500">{{ __('Actual (de-duped)') }}:</span> <span class="font-medium">{{ $campaign->total_contacts }}</span></div>
                        @else
                            <div><span class="text-gray-500">{{ __('Actual reach') }}:</span> <span class="text-gray-400 italic">{{ __('determined at launch') }}</span></div>
                        @endif
                    </div>

                    <ul class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200">
                        @foreach($campaign->contactGroups as $group)
                            <li class="flex items-center justify-between px-4 py-3 text-sm">
                                <div class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <span class="font-medium text-gray-900">{{ $group->name }}</span>
                                </div>
                                <div class="flex items-center gap-3 text-xs">
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800">
                                        {{ $group->active_contacts_count }} {{ __('active') }}
                                    </span>
                                    @if($group->total_contacts_count > $group->active_contacts_count)
                                        <span class="text-gray-400" title="{{ $group->total_contacts_count - $group->active_contacts_count }} {{ __('inactive contacts excluded from sends') }}">
                                            ({{ $group->total_contacts_count }} {{ __('total') }})
                                        </span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Message Logs --}}
            <div class="rounded-xl bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Message Logs</h3>
                <livewire:message-logs-table :campaignId="$campaign->id" />
            </div>
        </div>
    </div>
</x-app-layout>
