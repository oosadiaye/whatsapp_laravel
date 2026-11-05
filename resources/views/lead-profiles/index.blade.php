<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Lead profiles</h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 lg:px-8 space-y-4">
        @if($killSwitch)
            <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                Lead automation is paused. <a href="{{ route('lead-sources.index') }}" class="font-semibold underline">Resume it</a> to pull again.
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">Each profile describes one kind of lead. Pulled leads wait in <a href="{{ route('leads.index') }}" class="text-brand-text underline">Leads</a>.</p>
            <a href="{{ route('lead-profiles.create') }}" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">New profile</a>
        </div>

        @if($profiles->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="font-display text-lg font-semibold text-gray-900">No lead profiles yet</p>
                <p class="mt-1 text-sm text-gray-500">Describe the leads you want in plain English and BlastIQ builds the search for you.</p>
            </div>
        @else
            {{-- `relative` contains the table's absolutely-positioned sr-only header cell; without it the
                 span escapes the scroll wrapper and makes the whole page scroll sideways on phones. --}}
            <div class="relative overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Profile</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Sources</th>
                            <th class="px-4 py-3 text-right">Leads</th>
                            <th class="px-4 py-3">Last pulled</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($profiles as $profile)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('lead-profiles.edit', $profile) }}" class="font-medium text-gray-900 hover:underline">{{ $profile->name }}</a>
                                    @if($profile->last_pull_error)
                                        <p class="mt-0.5 max-w-md truncate text-xs text-red-600" title="{{ $profile->last_pull_error }}">{{ $profile->last_pull_error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if(! $profile->hasQuery())
                                        <span class="rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-semibold text-yellow-700">Needs search</span>
                                    @elseif($profile->is_active)
                                        <span class="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-text">On</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">Off</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ implode(', ', $profile->sources) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $profile->leads_count }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $profile->last_pulled_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('lead-profiles.edit', $profile) }}" class="text-sm font-medium text-brand-text hover:underline">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
