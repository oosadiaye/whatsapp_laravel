<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Settings</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
            <form action="{{ route('settings.update') }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 text-sm text-blue-900">
                    <p class="font-semibold mb-1">{{ __('WhatsApp credentials live per-instance.') }}</p>
                    <p>
                        {{ __('Each instance you connect carries its own Meta access token, app secret, and webhook config — set those up under') }}
                        <a href="{{ route('instances.index') }}" class="underline font-medium">{{ __('Instances') }}</a>.
                    </p>
                </div>

                {{-- Sending Defaults --}}
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-medium text-gray-900">Sending Defaults</h3>
                    <p class="mt-1 text-sm text-gray-500">Default values for new campaigns.</p>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Rate (messages/min)</label>
                            <input type="number" name="default_rate_per_minute" value="{{ $settings['default_rate_per_minute'] ?? 10 }}" min="1" max="60"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Default Country Code</label>
                            <input type="text" name="default_country_code" value="{{ $settings['default_country_code'] ?? '234' }}" maxlength="4"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Delay (seconds)</label>
                            <input type="number" name="default_delay_min" value="{{ $settings['default_delay_min'] ?? 2 }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Delay (seconds)</label>
                            <input type="number" name="default_delay_max" value="{{ $settings['default_delay_max'] ?? 8 }}" min="1"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                    </div>
                </div>

                {{-- Routing & Assignment --}}
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-medium text-gray-900">Routing & Assignment</h3>
                    <p class="mt-1 text-sm text-gray-500">Controls how inbound conversations are auto-assigned to agents.</p>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <label for="round_robin_cap_per_agent" class="block text-sm font-medium text-gray-700">
                                Round-robin cap per agent
                            </label>
                            <input type="number"
                                   name="round_robin_cap_per_agent"
                                   id="round_robin_cap_per_agent"
                                   min="0"
                                   max="1000"
                                   value="{{ old('round_robin_cap_per_agent', $settings['round_robin_cap_per_agent'] ?? 5) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            <p class="mt-1 text-xs text-gray-500">
                                Maximum active conversations auto-assigned to each agent. "Active" = inbound message
                                within the last 24 hours. Set to 0 to disable auto-assignment entirely (conversations
                                stay unassigned for managers to assign manually). Default 5.
                            </p>
                            @error('round_robin_cap_per_agent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Voice Provider (Africa's Talking) --}}
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-medium text-gray-900">Voice Provider (Africa's Talking)</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Credentials for outbound + inbound voice calls. The virtual number is your
                        outbound caller ID and also accepts inbound calls.
                    </p>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" name="africastalking_username"
                                   value="{{ old('africastalking_username', $settings['africastalking_username'] ?? '') }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">API Key</label>
                            <input type="password" name="africastalking_api_key"
                                   placeholder="{{ ($settings['africastalking_api_key'] ?? null) ? '••••••••' : '' }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing key. New value will be encrypted at rest.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Virtual Number (E.164)</label>
                            <input type="text" name="africastalking_virtual_number"
                                   value="{{ old('africastalking_virtual_number', $settings['africastalking_virtual_number'] ?? '') }}"
                                   placeholder="+234..."
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Rate per Minute (kobo)</label>
                            <input type="number" name="africastalking_rate_per_minute_kobo"
                                   value="{{ old('africastalking_rate_per_minute_kobo', $settings['africastalking_rate_per_minute_kobo'] ?? 600) }}"
                                   min="0" max="100000"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            <p class="mt-1 text-xs text-gray-500">Per-minute cost estimate. Default ₦6 = 600 kobo. Used for cost tracking on /calls.</p>
                        </div>
                    </div>
                </div>

                {{-- App Settings --}}
                <div class="rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-medium text-gray-900">Application</h3>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">App Name</label>
                            <input type="text" name="app_name" value="{{ $settings['app_name'] ?? 'BlastIQ' }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Timezone</label>
                            <input type="text" name="timezone" value="{{ $settings['timezone'] ?? 'Africa/Lagos' }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg bg-[#25D366] px-6 py-2 text-sm font-medium text-white hover:bg-[#1da851]">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
