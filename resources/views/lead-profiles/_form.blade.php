@php
    $filters = $profile?->hard_filters ?? [];
    $selectedSources = old('sources', $profile?->sources ?? ['apollo']);
@endphp

<div class="space-y-5">
    <div>
        <label for="lp-name" class="mb-1 block text-xs font-medium text-gray-600">Profile name</label>
        <input id="lp-name" type="text" name="name" value="{{ old('name', $profile?->name) }}" required maxlength="120"
               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="lp-brief" class="mb-1 block text-xs font-medium text-gray-600">Who are you looking for?</label>
        <textarea id="lp-brief" name="brief" rows="4" required minlength="20" maxlength="2000"
                  placeholder="e.g. Founders and CEOs of small fintech companies in Lagos, 10 to 50 staff"
                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">{{ old('brief', $profile?->brief) }}</textarea>
        <p class="mt-1 text-xs text-gray-500">Plain English. The AI turns this into the search, and later uses it to judge each lead.</p>
        @error('brief') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset>
        <legend class="mb-1 block text-xs font-medium text-gray-600">Lead platforms</legend>
        <div class="flex flex-wrap gap-4">
            @foreach($sourceKeys as $key)
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="sources[]" value="{{ $key }}" @checked(in_array($key, $selectedSources, true))>
                    {{ ucfirst($key) }}
                </label>
            @endforeach
        </div>
        @error('sources') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('sources.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </fieldset>

    <div class="max-w-xs">
        <label for="lp-cap" class="mb-1 block text-xs font-medium text-gray-600">New leads per day (soft limit)</label>
        <input id="lp-cap" type="number" name="daily_pull_cap" min="1" max="500" value="{{ old('daily_pull_cap', $profile?->daily_pull_cap ?? 50) }}"
               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
        @error('daily_pull_cap') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset class="rounded-xl border border-gray-200 p-4">
        <legend class="px-1 text-xs font-bold uppercase tracking-wide text-gray-500">Hard filters (always win over the AI)</legend>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="hf-countries" class="mb-1 block text-xs font-medium text-gray-600">Countries or cities (comma separated)</label>
                <input id="hf-countries" type="text" name="hf_countries" value="{{ old('hf_countries', implode(', ', $filters['countries'] ?? [])) }}"
                       placeholder="Nigeria, Ghana" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
            <div>
                <label for="hf-ranges" class="mb-1 block text-xs font-medium text-gray-600">Company size ranges (min,max; separate with ;)</label>
                <input id="hf-ranges" type="text" name="hf_employee_ranges" value="{{ old('hf_employee_ranges', implode('; ', $filters['employee_ranges'] ?? [])) }}"
                       placeholder="11,50; 51,200" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('hf_employee_ranges') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="hf-include" class="mb-1 block text-xs font-medium text-gray-600">Always include titles</label>
                <input id="hf-include" type="text" name="hf_titles_include" value="{{ old('hf_titles_include', implode(', ', $filters['titles_include'] ?? [])) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
            <div>
                <label for="hf-exclude" class="mb-1 block text-xs font-medium text-gray-600">Never include titles</label>
                <input id="hf-exclude" type="text" name="hf_titles_exclude" value="{{ old('hf_titles_exclude', implode(', ', $filters['titles_exclude'] ?? [])) }}"
                       placeholder="Intern, Student" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
            </div>
        </div>
        <label class="mt-4 inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="hf_require_verified_email" value="1" @checked(old('hf_require_verified_email', $filters['require_verified_email'] ?? false))>
            Only leads whose email the platform has verified
        </label>
    </fieldset>

    <fieldset class="rounded-xl border border-gray-200 p-4">
        <legend class="px-1 text-xs font-bold uppercase tracking-wide text-gray-500">Qualification and credits</legend>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="lp-hot" class="mb-1 block text-xs font-medium text-gray-600">Hot at score (1 to 100)</label>
                <input id="lp-hot" type="number" name="hot_threshold" min="1" max="100" value="{{ old('hot_threshold', $profile?->hot_threshold ?? 75) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('hot_threshold') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lp-warm" class="mb-1 block text-xs font-medium text-gray-600">Warm at score (not above hot)</label>
                <input id="lp-warm" type="number" name="warm_threshold" min="0" max="100" value="{{ old('warm_threshold', $profile?->warm_threshold ?? 50) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('warm_threshold') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lp-qcap" class="mb-1 block text-xs font-medium text-gray-600">AI judgements per day</label>
                <input id="lp-qcap" type="number" name="daily_qualify_cap" min="1" max="1000" value="{{ old('daily_qualify_cap', $profile?->daily_qualify_cap ?? 100) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('daily_qualify_cap') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lp-budget" class="mb-1 block text-xs font-medium text-gray-600">Email reveals per month (credits, 0 = never)</label>
                <input id="lp-budget" type="number" name="monthly_reveal_budget" min="0" max="100000" value="{{ old('monthly_reveal_budget', $profile?->monthly_reveal_budget ?? 200) }}"
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('monthly_reveal_budget') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">Only leads that score at least the warm threshold get their email revealed; each reveal uses about one platform credit.</p>
    </fieldset>

    @if($profile)
        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $profile->is_active))>
            Profile is on (pulls new leads automatically)
        </label>
    @endif
</div>
