<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Leads</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 lg:px-8 space-y-4">
        <form method="GET" action="{{ route('leads.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="f-status" class="mb-1 block text-xs font-medium text-gray-600">Status</label>
                <select id="f-status" name="status" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-profile" class="mb-1 block text-xs font-medium text-gray-600">Profile</label>
                <select id="f-profile" name="profile" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All</option>
                    @foreach($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected($profileId === $profile->id)>{{ $profile->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Filter</button>
        </form>

        @if($leads->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="font-display text-lg font-semibold text-gray-900">No leads yet</p>
                <p class="mt-1 text-sm text-gray-500">Create a lead profile and switch it on; matching leads appear here as they are pulled.</p>
                @can('leads.manage')
                    <a href="{{ route('lead-profiles.create') }}" class="mt-4 inline-block text-sm font-medium text-brand-text underline">New lead profile</a>
                @endcan
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Profile</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Score</th>
                            <th class="px-4 py-3">Tier</th>
                            <th class="px-4 py-3">Pulled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($leads as $lead)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900"><a href="{{ route('leads.show', $lead) }}" class="hover:underline">{{ $lead->name }}</a></td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->title ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->company ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($lead->email)
                                        <span class="font-data text-xs text-gray-800">{{ $lead->email }}</span>
                                    @else
                                        <span class="text-xs text-gray-500">Email hidden until qualified</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ ucfirst($lead->source) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $lead->profile?->name ?? '—' }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">{{ ucfirst($lead->status) }}</span></td>
                                <td class="px-4 py-3 tabular-nums text-gray-800" title="{{ $lead->qualification_reason }}">{{ $lead->score ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($lead->tier)
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lead->tier === 'hot' ? 'bg-red-50 text-red-700' : ($lead->tier === 'warm' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">{{ ucfirst($lead->tier) }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $lead->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $leads->links() }}
        @endif
    </div>
</x-app-layout>
