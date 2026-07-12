<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Settings') }}</h2>
    </x-slot>

    @php
        $atUsername = $settings['africastalking_username'] ?? '';
        $atKeySet   = (string) ($settings['africastalking_api_key'] ?? '') !== '';
        $atNumber   = $settings['africastalking_virtual_number'] ?? '';
        $atConfigured = $atUsername !== '' && $atKeySet && $atNumber !== '';
        $atStarted = $atUsername !== '' || $atKeySet || $atNumber !== '';
        $health = $atConfigured
            ? ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-600', 'label' => __('Configured')]
            : ($atStarted
                ? ['dot' => 'bg-amber-500', 'text' => 'text-amber-600', 'label' => __('Incomplete')]
                : ['dot' => 'bg-gray-400', 'text' => 'text-gray-500', 'label' => __('Not set')]);
        $check = fn (bool $ok) => $ok
            ? '<span class="text-emerald-600">&#10003;</span>'
            : '<span class="text-gray-300">&#10007;</span>';

        // WhatsApp (single instance) health.
        $waReady = $instance !== null && $instance->isReady();
        $waStarted = $instance !== null;
        $waHealth = $waReady
            ? ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-600', 'label' => __('Connected')]
            : ($waStarted
                ? ['dot' => 'bg-amber-500', 'text' => 'text-amber-600', 'label' => __('Incomplete')]
                : ['dot' => 'bg-gray-400', 'text' => 'text-gray-500', 'label' => __('Not set')]);
        $webhookUrl = $instance ? route('webhook.cloud.handle', $instance) : null;
        $waTokenSet = $instance && filled($instance->getRawOriginal('access_token'));
        $waSecretSet = $instance && filled($instance->getRawOriginal('app_secret'));
    @endphp

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-5">
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">{{ __('Settings & Integration') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('Configure sending defaults, routing, and voice connectivity.') }}</p>
        </div>

        @if(session('success'))
            <div class="mb-5 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800 flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('settings.update') }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                {{-- Main column --}}
                <div class="lg:col-span-2 space-y-6">
                    {{-- WhatsApp (Cloud API) — the single instance --}}
                    <div x-data="{ showToken: false }" class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-[#25D366]/10 text-[#128C7E]">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">{{ __('WhatsApp (Cloud API)') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('Your single Meta WhatsApp Business number. More numbers = a separate account.') }}</p>
                            </div>
                            <span class="ml-auto inline-flex items-center gap-1.5 text-sm font-semibold {{ $waHealth['text'] }}">
                                <span class="w-2 h-2 rounded-full {{ $waHealth['dot'] }}"></span>{{ $waHealth['label'] }}
                            </span>
                        </div>
                        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Display Name') }}</label>
                                <input type="text" name="wa_display_name" value="{{ old('wa_display_name', $instance->display_name ?? '') }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Phone Number ID') }}</label>
                                <input type="text" name="wa_phone_number_id" value="{{ old('wa_phone_number_id', $instance->phone_number_id ?? '') }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm font-mono focus:border-[#25D366] focus:ring-[#25D366]">
                                @error('wa_phone_number_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('WABA ID') }}</label>
                                <input type="text" name="wa_waba_id" value="{{ old('wa_waba_id', $instance->waba_id ?? '') }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm font-mono focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Webhook Verify Token') }}</label>
                                <input type="text" name="wa_webhook_verify_token" value="{{ old('wa_webhook_verify_token', $instance->webhook_verify_token ?? '') }}"
                                       placeholder="{{ __('auto-generated if blank') }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm font-mono focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Access Token') }}</label>
                                <div class="relative">
                                    <input :type="showToken ? 'text' : 'password'" name="wa_access_token"
                                           placeholder="{{ $waTokenSet ? '••••••••••••••••' : __('Meta system-user token') }}"
                                           class="block w-full rounded-lg border-gray-300 shadow-sm pr-10 focus:border-[#25D366] focus:ring-[#25D366]">
                                    <button type="button" @click="showToken = !showToken" class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                                        <svg x-show="!showToken" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <svg x-show="showToken" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ __('Leave blank to keep existing. Encrypted at rest.') }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('App Secret') }}</label>
                                <input type="password" name="wa_app_secret"
                                       placeholder="{{ $waSecretSet ? '••••••••••••••••' : __('Meta app secret') }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                <p class="mt-1 text-xs text-gray-500">{{ __('Verifies inbound webhook signatures. Leave blank to keep.') }}</p>
                            </div>
                        </div>
                        {{-- Webhook URL to paste into Meta --}}
                        <div class="px-6 pb-6 border-t border-gray-100 pt-4">
                            @if($webhookUrl)
                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Webhook URL (paste into Meta → WhatsApp → Configuration)') }}</p>
                                <code class="block w-full rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs font-mono text-gray-700 break-all">{{ $webhookUrl }}</code>
                            @else
                                <p class="text-xs text-gray-500">{{ __('Save your credentials to generate the webhook URL for Meta.') }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Africa's Talking credentials --}}
                    <div x-data="{ showKey: false }" class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H9v1.5H7.5v1.5H6a1.5 1.5 0 01-1.5-1.5v-1.629c0-.398.158-.78.44-1.06l4.68-4.68c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">{{ __('Voice Provider — Africa\'s Talking') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('Credentials for inbound + outbound calls. The virtual number is your caller ID.') }}</p>
                            </div>
                        </div>
                        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Username') }}</label>
                                <input type="text" name="africastalking_username"
                                       value="{{ old('africastalking_username', $atUsername) }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @error('africastalking_username')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('API Key') }}</label>
                                <div class="relative">
                                    <input :type="showKey ? 'text' : 'password'" name="africastalking_api_key"
                                           placeholder="{{ $atKeySet ? '••••••••••••••••' : __('Enter key') }}"
                                           class="block w-full rounded-lg border-gray-300 shadow-sm pr-10 focus:border-[#25D366] focus:ring-[#25D366]">
                                    <button type="button" @click="showKey = !showKey"
                                            class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600"
                                            :title="showKey ? '{{ __('Hide') }}' : '{{ __('Show') }}'">
                                        <svg x-show="!showKey" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <svg x-show="showKey" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ __('Leave blank to keep existing key. New value is encrypted at rest.') }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Virtual Number (E.164)') }}</label>
                                <input type="text" name="africastalking_virtual_number"
                                       value="{{ old('africastalking_virtual_number', $atNumber) }}" placeholder="+234..."
                                       class="block w-full rounded-lg border-gray-300 shadow-sm font-mono focus:border-[#25D366] focus:ring-[#25D366]">
                                @error('africastalking_virtual_number')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Rate / Minute (kobo)') }}</label>
                                <input type="number" name="africastalking_rate_per_minute_kobo" min="0" max="100000"
                                       value="{{ old('africastalking_rate_per_minute_kobo', $settings['africastalking_rate_per_minute_kobo'] ?? 600) }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                <p class="mt-1 text-xs text-gray-500">{{ __('Per-minute cost estimate (₦6 = 600 kobo). Used for /calls cost tracking.') }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Sending defaults --}}
                    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">{{ __('Sending Defaults') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('Default throttle values for new campaigns.') }}</p>
                            </div>
                        </div>
                        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Rate (messages/min)') }}</label>
                                <input type="number" name="default_rate_per_minute" min="1" max="60"
                                       value="{{ $settings['default_rate_per_minute'] ?? 10 }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Default Country Code') }}</label>
                                <input type="text" name="default_country_code" maxlength="4"
                                       value="{{ $settings['default_country_code'] ?? '234' }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Min Delay (seconds)') }}</label>
                                <input type="number" name="default_delay_min" min="1"
                                       value="{{ $settings['default_delay_min'] ?? 2 }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Max Delay (seconds)') }}</label>
                                <input type="number" name="default_delay_max" min="1"
                                       value="{{ $settings['default_delay_max'] ?? 8 }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                        </div>
                    </div>

                    {{-- Routing --}}
                    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-blue-50 text-blue-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">{{ __('Routing & Assignment') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('How inbound conversations are auto-assigned to agents.') }}</p>
                            </div>
                        </div>
                        <div class="p-6">
                            <label for="round_robin_cap_per_agent" class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Round-robin cap per agent') }}</label>
                            <input type="number" name="round_robin_cap_per_agent" id="round_robin_cap_per_agent" min="0" max="1000"
                                   value="{{ old('round_robin_cap_per_agent', $settings['round_robin_cap_per_agent'] ?? 5) }}"
                                   class="block w-full sm:w-1/2 rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            <p class="mt-1 text-xs text-gray-500">{{ __('Max active conversations auto-assigned per agent (inbound within 24h). 0 disables auto-assignment. Default 5.') }}</p>
                            @error('round_robin_cap_per_agent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    {{-- Application --}}
                    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-gray-100 text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">{{ __('Application') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('General app identity.') }}</p>
                            </div>
                        </div>
                        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('App Name') }}</label>
                                <input type="text" name="app_name" value="{{ $settings['app_name'] ?? 'BlastIQ' }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Timezone') }}</label>
                                <input type="text" name="timezone" value="{{ $settings['timezone'] ?? 'Africa/Lagos' }}"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sidebar --}}
                <div class="space-y-6">
                    {{-- Voice integration health (real, from settings presence) --}}
                    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                            </span>
                            <h3 class="text-base font-bold text-gray-900">{{ __('Voice integration') }}</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">{{ __("Africa's Talking") }}</span>
                                <span class="inline-flex items-center gap-1.5 text-sm font-semibold {{ $health['text'] }}">
                                    <span class="w-2 h-2 rounded-full {{ $health['dot'] }}"></span>{{ $health['label'] }}
                                </span>
                            </div>
                            <ul class="text-sm text-gray-600 space-y-1.5">
                                <li class="flex items-center justify-between"><span>{{ __('Username') }}</span><span>{!! $check($atUsername !== '') !!}</span></li>
                                <li class="flex items-center justify-between"><span>{{ __('API key') }}</span><span>{!! $check($atKeySet) !!}</span></li>
                                <li class="flex items-center justify-between"><span>{{ __('Virtual number') }}</span><span>{!! $check($atNumber !== '') !!}</span></li>
                            </ul>
                            @unless($atConfigured)
                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    {{ __('Complete all three fields to enable voice calling.') }}
                                </p>
                            @endunless
                        </div>
                    </div>

                    {{-- WhatsApp connection status --}}
                    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
                            <span class="grid place-items-center w-9 h-9 rounded-lg bg-[#25D366]/10 text-[#128C7E]">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            </span>
                            <h3 class="text-base font-bold text-gray-900">{{ __('WhatsApp connection') }}</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">{{ __('Status') }}</span>
                                <span class="inline-flex items-center gap-1.5 text-sm font-semibold {{ $waHealth['text'] }}">
                                    <span class="w-2 h-2 rounded-full {{ $waHealth['dot'] }}"></span>{{ $waHealth['label'] }}
                                </span>
                            </div>
                            <ul class="text-sm text-gray-600 space-y-1.5">
                                <li class="flex items-center justify-between"><span>{{ __('Phone number ID') }}</span><span>{!! $check($instance && filled($instance->phone_number_id)) !!}</span></li>
                                <li class="flex items-center justify-between"><span>{{ __('WABA ID') }}</span><span>{!! $check($instance && filled($instance->waba_id)) !!}</span></li>
                                <li class="flex items-center justify-between"><span>{{ __('Access token') }}</span><span>{!! $check($waTokenSet) !!}</span></li>
                                <li class="flex items-center justify-between"><span>{{ __('App secret') }}</span><span>{!! $check($waSecretSet) !!}</span></li>
                            </ul>
                            @if($instance && $instance->quality_rating)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500">{{ __('Quality') }}</span>
                                    <span class="font-semibold text-gray-800">{{ $instance->quality_rating }}</span>
                                </div>
                            @endif
                            @unless($waReady)
                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    {{ __('Add Phone Number ID, WABA ID and Access Token to start sending.') }}
                                </p>
                            @endunless
                        </div>
                    </div>

                    <div class="hidden lg:block">
                        <button type="submit" class="w-full rounded-lg bg-[#25D366] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#1da851] transition shadow-sm">
                            {{ __('Save Settings') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- Mobile save --}}
            <div class="mt-6 flex justify-end lg:hidden">
                <button type="submit" class="rounded-lg bg-[#25D366] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#1da851] transition shadow-sm">
                    {{ __('Save Settings') }}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
