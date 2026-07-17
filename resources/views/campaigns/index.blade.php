@php
    // How many of THIS user's campaigns are currently QUEUED or RUNNING — the
    // states that "Clear queue" would actually do something to. We use this
    // count to (a) decide whether to render the bulk-clear button at all
    // and (b) show it inline on the button label as social proof.
    $stuckCount = \App\Models\Campaign::where('user_id', auth()->id())
        ->whereIn('status', ['QUEUED', 'RUNNING'])
        ->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Campaigns</h2>
            <div class="flex items-center gap-2">
                @can('campaigns.cancel')
                    @if($stuckCount > 0)
                        <form action="{{ route('campaigns.clearQueue') }}" method="POST" class="inline"
                              data-confirm="{{ __('Cancel all') }} {{ $stuckCount }} {{ __('queued/running campaigns and abort their pending sends? This cannot be undone.') }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                                    title="{{ __('Useful when the queue worker has stalled — bulk-cancels everything currently QUEUED or RUNNING') }}">
                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                {{ __('Clear queue') }} ({{ $stuckCount }})
                            </button>
                        </form>
                    @endif
                @endcan
                <a href="{{ route('campaigns.create') }}" class="inline-flex items-center rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Campaign
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6"
         x-data="{
             selected: [],
             /* selectableIds holds the IDs of campaigns whose status allows
                deletion. Used by the master 'select all' so that clicking it
                only toggles deletable rows, not running ones. Mirrors the
                DELETABLE_STATUSES whitelist in CampaignController. */
             selectableIds: @js($campaigns->whereIn('status', ['DRAFT', 'QUEUED', 'PAUSED', 'COMPLETED', 'FAILED', 'CANCELLED'])->pluck('id')->all()),
             get allSelected() {
                 return this.selectableIds.length > 0
                     && this.selected.length === this.selectableIds.length;
             },
             toggleAll(checked) {
                 this.selected = checked ? [...this.selectableIds] : [];
             }
         }">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            {{-- Bulk action bar — fades in only when something is selected so
                 the page stays clean for browsing. Uses a separate form from
                 each row's actions so the checkboxes can be re-bound via
                 hidden inputs that mirror the Alpine selected[] array. --}}
            @can('campaigns.delete')
                <div x-show="selected.length > 0" x-cloak x-transition.opacity
                     class="mb-3 flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <span class="text-sm text-amber-900">
                        <span class="font-semibold" x-text="selected.length"></span> selected
                    </span>
                    <form action="{{ route('campaigns.bulkDestroy') }}" method="POST" class="ml-auto"
                          @submit.prevent="if (confirm(`Delete ${selected.length} selected campaign(s)? Running ones will be skipped. This cannot be undone.`)) $el.submit()">
                        @csrf
                        {{-- Bridge Alpine state → form submission. x-for emits
                             one hidden input per selected ID, all named ids[]
                             so Laravel's validator gets an array. --}}
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                        <button type="submit"
                                class="inline-flex items-center rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                            <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Delete selected
                        </button>
                    </form>
                    <button type="button" @click="selected = []"
                            class="text-sm text-amber-700 hover:text-amber-900 hover:underline">
                        Clear
                    </button>
                </div>
            @endcan

            <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            @can('campaigns.delete')
                                <th class="w-10 px-4 py-3">
                                    <input type="checkbox"
                                           :checked="allSelected"
                                           :disabled="selectableIds.length === 0"
                                           @change="toggleAll($event.target.checked)"
                                           class="rounded border-gray-300 text-[#25D366] focus:ring-[#25D366]"
                                           title="Select all deletable campaigns on this page">
                                </th>
                            @endcan
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
                        @php $isDeletable = in_array($campaign->status, ['DRAFT', 'QUEUED', 'PAUSED', 'COMPLETED', 'FAILED', 'CANCELLED'], true); @endphp
                        <tr class="hover:bg-gray-50"
                            :class="selected.includes(@js($campaign->id)) ? 'bg-amber-50/40' : ''">
                            @can('campaigns.delete')
                                <td class="w-10 px-4 py-4">
                                    {{-- Running campaigns get a disabled checkbox with a tooltip
                                         instead of being hidden — visible feedback that the row
                                         CAN'T be bulk-deleted is more helpful than silent absence. --}}
                                    <input type="checkbox"
                                           value="{{ $campaign->id }}"
                                           x-model="selected"
                                           @if(! $isDeletable) disabled @endif
                                           class="rounded border-gray-300 text-[#25D366] focus:ring-[#25D366] disabled:opacity-40 disabled:cursor-not-allowed"
                                           title="{{ $isDeletable ? 'Select for bulk action' : 'Pause or cancel a running campaign before deleting' }}">
                                </td>
                            @endcan
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
                            {{-- colspan accounts for the optional checkbox column.
                                 Base = 7 (Name/Status/Sent/Delivered/Failed/Scheduled/Actions),
                                 +1 when the bulk-delete checkbox column is rendered. --}}
                            <td colspan="{{ auth()->user()?->can('campaigns.delete') ? 8 : 7 }}" class="px-6 py-12 text-center text-gray-500">No campaigns yet. Create your first campaign!</td>
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
