@php($campaign = $campaign ?? null)
@php($selectedGroups = old('groups', $campaign?->contactGroups->pluck('id')->all() ?? []))
<form method="POST" action="{{ $campaign ? route('email-campaigns.update', $campaign) : route('email-campaigns.store') }}"
      x-data="{ recurrence: '{{ old('recurrence', $campaign?->recurrence ?? 'none') }}', schedule: {{ old('scheduled_at', $campaign?->scheduled_at) ? 'true' : 'false' }} }"
      class="space-y-6">
    @csrf
    @if($campaign) @method('PUT') @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        {{-- Main column --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Campaign name') }} *</label>
                    <input type="text" name="name" value="{{ old('name', $campaign?->name) }}" required
                           class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Subject') }} *</label>
                    <input type="text" name="subject" value="{{ old('subject', $campaign?->subject) }}" required
                           class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('subject')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('From name') }}</label>
                        <input type="text" name="from_name" value="{{ old('from_name', $campaign?->from_name) }}" placeholder="{{ config('mail.from.name') }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Reply-to') }}</label>
                        <input type="email" name="reply_to" value="{{ old('reply_to', $campaign?->reply_to) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('reply_to')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                @php($emailTemplates = $templates ?? [])
                @if(! empty($emailTemplates))
                    <div x-data class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Start from a beautiful template') }}</label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                            @foreach($emailTemplates as $tpl)
                                <button type="button"
                                        @click="const ta = document.getElementById('body_html'); ta.value = document.getElementById('email-tpl-{{ $tpl['key'] }}').innerHTML.trim(); ta.dispatchEvent(new Event('input', { bubbles: true }));"
                                        class="group text-left rounded-lg border border-gray-200 bg-white overflow-hidden hover:border-gray-300 hover:shadow-md transition focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                    <span class="block h-1.5" style="background: {{ $tpl['accent'] }}"></span>
                                    <span class="block px-3 py-2">
                                        <span class="block text-sm font-semibold text-gray-800">{{ $tpl['name'] }}</span>
                                        <span class="block text-[11px] text-gray-400 mt-0.5 leading-snug">{{ $tpl['description'] }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">{{ __('Click a template to load it into the editor below, then make it yours.') }}</p>

                        {{-- Inert HTML sources for the picker (parsed but not rendered). --}}
                        @foreach($emailTemplates as $tpl)
                            <template id="email-tpl-{{ $tpl['key'] }}">{!! $tpl['html'] !!}</template>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Email body (HTML)') }} *</label>
                    <textarea name="body_html" id="body_html" rows="12" required
                              class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('body_html', $campaign?->body_html) }}</textarea>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Personalize with') }} <code>@{{name}}</code> {{ __('and') }} <code>@{{email}}</code>. {{ __('An unsubscribe link is added automatically.') }}</p>
                    @error('body_html')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-700 mb-3">{{ __('Recipients') }} *</h3>
                <div class="space-y-2 max-h-60 overflow-y-auto">
                    @forelse($groups as $group)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="groups[]" value="{{ $group->id }}"
                                   {{ in_array($group->id, $selectedGroups) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-gray-700">{{ $group->name }} <span class="text-gray-400">({{ $group->contacts_count ?? $group->contact_count }})</span></span>
                        </label>
                    @empty
                        <p class="text-xs text-gray-400">{{ __('No contact groups yet.') }}</p>
                    @endforelse
                </div>
                <p class="mt-2 text-[11px] text-gray-400">{{ __('Only contacts with an email (and not unsubscribed) are sent to.') }}</p>
                @error('groups')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-700">{{ __('Delivery') }}</h3>
                <div>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Send rate (emails / minute)') }}</label>
                    <input type="number" name="rate_per_minute" min="1" max="1000" value="{{ old('rate_per_minute', $campaign?->rate_per_minute ?? 60) }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="schedule" class="rounded border-gray-300 text-indigo-600">
                    <span class="text-gray-700">{{ __('Schedule for later') }}</span>
                </label>
                <div x-show="schedule" x-cloak class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">{{ __('Send at') }}</label>
                        <input type="datetime-local" name="scheduled_at"
                               value="{{ old('scheduled_at', optional($campaign?->scheduled_at)->format('Y-m-d\TH:i')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('scheduled_at')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">{{ __('Repeat') }}</label>
                        <select name="recurrence" x-model="recurrence" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="none">{{ __('Does not repeat') }}</option>
                            <option value="daily">{{ __('Daily') }}</option>
                            <option value="weekly">{{ __('Weekly') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
                        </select>
                    </div>
                    <div x-show="recurrence !== 'none'" x-cloak>
                        <label class="block text-xs font-medium text-gray-600">{{ __('Repeat until (optional)') }}</label>
                        <input type="datetime-local" name="recurrence_until"
                               value="{{ old('recurrence_until', optional($campaign?->recurrence_until)->format('Y-m-d\TH:i')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <button type="submit" name="action" value="send" x-show="!schedule"
                        class="w-full px-4 py-2.5 rounded-lg bg-[#4f46e5] text-white font-semibold text-sm hover:bg-[#4338ca] transition">
                    {{ __('Send now') }}
                </button>
                <button type="submit" name="action" value="schedule" x-show="schedule" x-cloak
                        class="w-full px-4 py-2.5 rounded-lg bg-[#4f46e5] text-white font-semibold text-sm hover:bg-[#4338ca] transition">
                    {{ __('Schedule') }}
                </button>
                <button type="submit" name="action" value="draft"
                        class="w-full px-4 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-700 font-semibold text-sm hover:bg-gray-50 transition">
                    {{ __('Save as draft') }}
                </button>
            </div>
        </div>
    </div>
</form>
