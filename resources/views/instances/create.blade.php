<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Connect New Instance') }}
        </h2>
    </x-slot>

    <div class="py-8" x-data="{ driver: '{{ old('driver', 'cloud') }}' }">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Driver picker --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <button type="button"
                        @click="driver = 'cloud'"
                        :class="driver === 'cloud' ? 'border-[#25D366] ring-2 ring-[#25D366]/20 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'"
                        class="text-left p-5 rounded-xl border-2 transition bg-white">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-semibold text-gray-900">{{ __('WhatsApp Cloud API') }}</span>
                        <span class="text-[10px] uppercase tracking-wide bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-semibold">{{ __('Recommended') }}</span>
                    </div>
                    <p class="text-sm text-gray-600">{{ __('Official Meta API. Templates, no ban risk, production-grade. Requires a Meta Business account.') }}</p>
                </button>

                <button type="button"
                        @click="driver = 'evolution'"
                        :class="driver === 'evolution' ? 'border-amber-500 ring-2 ring-amber-500/20 bg-amber-50' : 'border-gray-200 hover:border-gray-300'"
                        class="text-left p-5 rounded-xl border-2 transition bg-white">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-semibold text-gray-900">{{ __('Evolution API') }}</span>
                        <span class="text-[10px] uppercase tracking-wide bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold">{{ __('Legacy') }}</span>
                    </div>
                    <p class="text-sm text-gray-600">{{ __('QR-code scan via WhatsApp Web protocol. No approval needed but phone numbers can be banned by WhatsApp.') }}</p>
                </button>
            </div>

            {{-- Form --}}
            <div class="bg-white overflow-hidden shadow-sm rounded-xl">
                <div class="p-6">
                    <form method="POST" action="{{ route('instances.store') }}" class="space-y-6">
                        @csrf
                        <input type="hidden" name="driver" :value="driver">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="instance_name" :value="__('Internal Name')" />
                                <x-text-input id="instance_name"
                                              name="instance_name"
                                              type="text"
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
                                <x-text-input id="display_name"
                                              name="display_name"
                                              type="text"
                                              class="mt-1 block w-full"
                                              :value="old('display_name')"
                                              placeholder="e.g. Customer Support" />
                                <x-input-error :messages="$errors->get('display_name')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Cloud API fields --}}
                        <div x-show="driver === 'cloud'" x-cloak class="space-y-4 pt-4 border-t border-gray-100">
                            <h3 class="font-semibold text-gray-800">{{ __('Meta credentials') }}</h3>
                            <p class="text-sm text-gray-600">
                                {{ __('Find these in your') }}
                                <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" class="text-[#25D366] hover:underline">Meta App dashboard</a>
                                → WhatsApp → API Setup.
                            </p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="waba_id" :value="__('WhatsApp Business Account ID')" />
                                    <x-text-input id="waba_id" name="waba_id" type="text"
                                                  class="mt-1 block w-full font-mono text-sm"
                                                  :value="old('waba_id')"
                                                  placeholder="e.g. 102290129340398" />
                                    <x-input-error :messages="$errors->get('waba_id')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="phone_number_id" :value="__('Phone Number ID')" />
                                    <x-text-input id="phone_number_id" name="phone_number_id" type="text"
                                                  class="mt-1 block w-full font-mono text-sm"
                                                  :value="old('phone_number_id')"
                                                  placeholder="e.g. 109876543210987" />
                                    <x-input-error :messages="$errors->get('phone_number_id')" class="mt-2" />
                                </div>
                            </div>

                            <div>
                                <x-input-label for="access_token" :value="__('Access Token')" />
                                <textarea id="access_token" name="access_token" rows="3"
                                          class="mt-1 block w-full font-mono text-xs border-gray-300 rounded-md shadow-sm focus:border-[#25D366] focus:ring-[#25D366]"
                                          placeholder="EAAxxxxxx...">{{ old('access_token') }}</textarea>
                                <p class="mt-1 text-xs text-gray-500">{{ __('Use a System User permanent token in production — short-lived tokens expire in 24h.') }}</p>
                                <x-input-error :messages="$errors->get('access_token')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="app_secret" :value="__('App Secret')" />
                                <x-text-input id="app_secret" name="app_secret" type="password"
                                              class="mt-1 block w-full font-mono text-sm"
                                              :value="old('app_secret')"
                                              placeholder="••••••••••••••••" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('Used to verify webhook signatures (HMAC-SHA256). Found under App settings → Basic.') }}</p>
                                <x-input-error :messages="$errors->get('app_secret')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="webhook_verify_token" :value="__('Webhook Verify Token')" />
                                <x-text-input id="webhook_verify_token" name="webhook_verify_token" type="text"
                                              class="mt-1 block w-full font-mono text-sm"
                                              :value="old('webhook_verify_token')"
                                              placeholder="{{ __('Leave blank to auto-generate') }}" />
                                <p class="mt-1 text-xs text-gray-500">{{ __('You\'ll paste this into Meta\'s webhook config. We\'ll show you the matching URL after save.') }}</p>
                                <x-input-error :messages="$errors->get('webhook_verify_token')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Evolution-only notice --}}
                        <div x-show="driver === 'evolution'" x-cloak class="pt-4 border-t border-gray-100">
                            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
                                <p class="font-semibold mb-1">{{ __('Heads up — ban risk.') }}</p>
                                <p>{{ __('Bulk messaging via Baileys violates WhatsApp\'s ToS. Numbers can be banned without notice. Use only for testing or low-volume internal use.') }}</p>
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
                                <span x-text="driver === 'cloud' ? '{{ __('Connect & Verify') }}' : '{{ __('Create & Show QR') }}'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
