<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $profile->name }}</h2>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 lg:px-8 space-y-6">

        <form method="POST" action="{{ route('lead-profiles.update', $profile) }}" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            @include('lead-profiles._form', ['profile' => $profile])
            <div class="mt-6">
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save profile</button>
            </div>
        </form>

        {{-- The generated search, for the admin to read and trust. Its own section: forms must not nest. --}}
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="gen-search">
            <h3 id="gen-search" class="font-display text-lg font-bold text-gray-900">Generated search</h3>

            @if($query)
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                    @foreach([
                        'Job titles' => $query->titles,
                        'Excluded titles' => $query->excludeTitles,
                        'Seniority' => $query->seniorities,
                        'Person location' => $query->personLocations,
                        'Company location' => $query->organizationLocations,
                        'Company size' => $query->employeeRanges,
                        'Keywords' => $query->keywords === '' ? [] : [$query->keywords],
                    ] as $label => $values)
                        @continue($values === [])
                        <dt class="font-medium text-gray-600">{{ $label }}</dt>
                        <dd class="text-gray-900">{{ implode(', ', $values) }}</dd>
                    @endforeach
                    @if($query->requireVerifiedEmail)
                        <dt class="font-medium text-gray-600">Email</dt>
                        <dd class="text-gray-900">Verified only</dd>
                    @endif
                </dl>
            @else
                <p class="mt-2 text-sm text-gray-600">No search yet. Rebuild it from the brief; the profile cannot be switched on without one.</p>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('lead-profiles.rebuild-query', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Rebuild search from brief</button>
                </form>
                <form method="POST" action="{{ route('lead-profiles.pull', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Pull a page now</button>
                </form>
                <form method="POST" action="{{ route('lead-profiles.reset-pull', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Start from page one</button>
                </form>
            </div>

            <div class="mt-3">
                <form method="POST" action="{{ route('lead-profiles.qualify', $profile) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Qualify new leads now</button>
                </form>
            </div>

            @if($profile->last_pull_error)
                <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $profile->last_pull_error }}</p>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="pipeline">
            <h3 id="pipeline" class="font-display text-lg font-bold text-gray-900">Pipeline and credits</h3>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
                <dt class="font-medium text-gray-600">Reveal credits this month</dt>
                <dd class="text-gray-900">{{ $creditsUsed }} of {{ $profile->monthly_reveal_budget }}</dd>
                @foreach(['new' => 'Waiting to be judged', 'qualified' => 'Qualified', 'rejected' => 'Rejected'] as $status => $label)
                    <dt class="font-medium text-gray-600">{{ $label }}</dt>
                    <dd class="text-gray-900 tabular-nums">{{ $pipeline[$status] ?? 0 }}</dd>
                @endforeach
            </dl>
            @if($profile->last_qualify_error)
                <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $profile->last_qualify_error }}</p>
            @endif
        </section>

        <section class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
            <h3 class="font-display text-base font-bold text-gray-900">Delete this profile</h3>
            <p class="mt-1 text-sm text-gray-600">Leads it already pulled are kept.</p>
            <form method="POST" action="{{ route('lead-profiles.destroy', $profile) }}" class="mt-3"
                  onsubmit="return confirm('Delete this lead profile?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Delete profile</button>
            </form>
        </section>
    </div>
</x-app-layout>
