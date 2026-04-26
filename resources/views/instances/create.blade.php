<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Connect WhatsApp Cloud API Instance') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-900">
                <p class="font-semibold mb-1">{{ __('Before you start') }}</p>
                <p>
                    {{ __('You\'ll need a Meta Business account with a WhatsApp Business app set up. Find your credentials at') }}
                    <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" class="underline font-medium">developers.facebook.com/apps</a>
                    → {{ __('your app') }} → WhatsApp → API Setup.
                </p>
            </div>

            <div class="bg-white shadow-sm rounded-xl">
                <div class="p-6">
                    <form method="POST" action="{{ route('instances.store') }}" class="space-y-6">
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="instance_name" :value="__('Internal Name')" />
                                <x-text-input id="instance_name" name="instance_name" type="text"
                                              class="mt-1 block w-full"
                                              :value="old('instance_name')"
                                              required
                                              autofocus
                                              placeholder="e.g. main_business_line" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('Used internally for routing — not shown to customers.') }}</p>
                                <x-input-error :messages="$errors->get('instance_name')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="display_name" :value="__('Display Name')" />
                                <x-text-input id="display_name" name="display_name" type="text"
                                              class="mt-1 block w-full"
                                              :value="old('display_name')"
                                              placeholder="e.g. Customer Support" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('Auto-filled from Meta\'s verified name on save.') }}</p>
                                <x-input-error :messages="$errors->get('display_name')" class="mt-2" />
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 space-y-4">
                            <h3 class="font-semibold text-gray-800">{{ __('Meta credentials') }}</h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="waba_id" :value="__('WhatsApp Business Account ID')" />
                                    <x-text-input id="waba_id" name="waba_id" type="text"
                                                  class="mt-1 block w-full font-mono text-sm"
                                                  :value="old('waba_id')"
                                                  required
                                                  placeholder="e.g. 102290129340398" />
                                    <x-input-error :messages="$errors->get('waba_id')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="phone_number_id" :value="__('Phone Number ID')" />
                                    <x-text-input id="phone_number_id" name="phone_number_id" type="text"
                                                  class="mt-1 block w-full font-mono text-sm"
                                                  :value="old('phone_number_id')"
                                                  required
                                                  placeholder="e.g. 109876543210987" />
                                    <x-input-error :messages="$errors->get('phone_number_id')" class="mt-2" />
                                </div>
                            </div>

                            <div>
                                <x-input-label for="access_token" :value="__('Access Token')" />
                                <textarea id="access_token" name="access_token" rows="3" required
                                          class="mt-1 block w-full font-mono text-xs border-gray-300 rounded-md shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"
                                          placeholder="EAAxxxxxx...">{{ old('access_token') }}</textarea>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Use a System User permanent token in production — short-lived tokens expire in 24h.') }}
                                </p>
                                <x-input-error :messages="$errors->get('access_token')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="app_secret" :value="__('App Secret')" />
                                <x-text-input id="app_secret" name="app_secret" type="password"
                                              class="mt-1 block w-full font-mono text-sm"
                                              :value="old('app_secret')"
                                              required
                                              placeholder="••••••••••••••••" />
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Used to verify webhook signatures (HMAC-SHA256). App settings → Basic.') }}
                                </p>
                                <x-input-error :messages="$errors->get('app_secret')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="webhook_verify_token" :value="__('Webhook Verify Token')" />
                                <x-text-input id="webhook_verify_token" name="webhook_verify_token" type="text"
                                              class="mt-1 block w-full font-mono text-sm"
                                              :value="old('webhook_verify_token')"
                                              placeholder="{{ __('Leave blank to auto-generate') }}" />
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('We\'ll show you the matching webhook URL after save so you can paste both into Meta.') }}
                                </p>
                                <x-input-error :messages="$errors->get('webhook_verify_token')" class="mt-2" />
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100">
                            <a href="{{ route('instances.index') }}"
                               class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                                {{ __('Cancel') }}
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center px-5 py-2 text-sm font-medium text-white rounded-lg shadow-sm hover:opacity-90 transition"
                                    style="background-color: #25D366;">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                {{ __('Connect & Verify') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
