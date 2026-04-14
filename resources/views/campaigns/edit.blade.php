<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit Campaign: {{ $campaign->name }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            <form action="{{ route('campaigns.update', $campaign) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-xl bg-white p-6 shadow-sm space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Campaign Name *</label>
                        <input type="text" name="name" value="{{ old('name', $campaign->name) }}" required
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">WhatsApp Instance</label>
                        <select name="instance_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                            <option value="">Select instance...</option>
                            @foreach($instances as $instance)
                            <option value="{{ $instance->id }}" {{ old('instance_id', $campaign->instance_id) == $instance->id ? 'selected' : '' }}>
                                {{ $instance->display_name ?? $instance->instance_name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Contact Groups *</label>
                        @php $selectedGroups = old('groups', $campaign->groups->pluck('id')->toArray()); @endphp
                        @foreach($groups as $group)
                        <label class="mt-2 flex items-center">
                            <input type="checkbox" name="groups[]" value="{{ $group->id }}"
                                   {{ in_array($group->id, $selectedGroups) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-[#25D366]">
                            <span class="ml-2 text-sm">{{ $group->name }} ({{ $group->contacts_count ?? $group->contact_count }})</span>
                        </label>
                        @endforeach
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Message *</label>
                        <textarea name="message" rows="6" required
                                  class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">{{ old('message', $campaign->message) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Media (optional)</label>
                        @if($campaign->media_path)
                        <p class="text-sm text-gray-500">Current: {{ basename($campaign->media_path) }}</p>
                        @endif
                        <input type="file" name="media" accept="image/*,.pdf,.mp3,.ogg"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-[#25D366] file:px-4 file:py-2 file:text-sm file:text-white">
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Rate (msgs/min)</label>
                            <input type="number" name="rate_per_minute" value="{{ old('rate_per_minute', $campaign->rate_per_minute) }}" min="1" max="60"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Delay (s)</label>
                            <input type="number" name="delay_min" value="{{ old('delay_min', $campaign->delay_min) }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Delay (s)</label>
                            <input type="number" name="delay_max" value="{{ old('delay_max', $campaign->delay_max) }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Schedule (optional)</label>
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <a href="{{ route('campaigns.show', $campaign) }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                    <button type="submit" class="rounded-lg bg-[#25D366] px-4 py-2 text-sm font-medium text-white hover:bg-[#1da851]">Update Campaign</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
