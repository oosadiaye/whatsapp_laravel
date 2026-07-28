<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Connect a Mailbox') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-lg p-6">
                <form method="POST" action="{{ route('mailbox.accounts.store') }}" class="space-y-5" x-data='{
                    provider: "imap",
                    presets: {
                        gmail: { imap_host: "imap.gmail.com", imap_port: 993, imap_encryption: "ssl", smtp_host: "smtp.gmail.com", smtp_port: 587, smtp_encryption: "tls" },
                        outlook: { imap_host: "outlook.office365.com", imap_port: 993, imap_encryption: "ssl", smtp_host: "smtp.office365.com", smtp_port: 587, smtp_encryption: "starttls" },
                        yahoo: { imap_host: "imap.mail.yahoo.com", imap_port: 993, imap_encryption: "ssl", smtp_host: "smtp.mail.yahoo.com", smtp_port: 465, smtp_encryption: "ssl" },
                    },
                    applyPreset(name) {
                        if (!this.presets[name]) return;
                        var p = this.presets[name];
                        Object.keys(p).forEach(function(key) {
                            var el = document.querySelector(`[name="${key}"]`);
                            if (el) el.value = p[key];
                        });
                    }
                }'>
                    @csrf

                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <label class="text-sm font-semibold text-gray-700">{{ __('Email Provider') }}</label>
                        <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" @click="provider='gmail'; applyPreset('gmail')"
                                class="flex flex-col items-center gap-1 px-3 py-3 rounded-lg border-2 transition text-sm font-medium"
                                :class="provider === 'gmail' ? 'border-[#25D366] bg-[#25D366]/5 text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                                <span>Gmail</span>
                            </button>
                            <button type="button" @click="provider='outlook'; applyPreset('outlook')"
                                class="flex flex-col items-center gap-1 px-3 py-3 rounded-lg border-2 transition text-sm font-medium"
                                :class="provider === 'outlook' ? 'border-[#25D366] bg-[#25D366]/5 text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M21 5v14l-8-3V8l8-3zM4 6h7v12H4V6z" fill="#0078D4"/><path d="M11 6l5 2v8l-5 2V6z" fill="#0078D4"/></svg>
                                <span>Outlook</span>
                            </button>
                            <button type="button" @click="provider='yahoo'; applyPreset('yahoo')"
                                class="flex flex-col items-center gap-1 px-3 py-3 rounded-lg border-2 transition text-sm font-medium"
                                :class="provider === 'yahoo' ? 'border-[#25D366] bg-[#25D366]/5 text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M11.5 1.5L16.5 11H20L14 1.5H11.5zM7.5 1.5L4 11h3.5l2-5.5L11.5 11H15L9.5 1.5H7.5zM15 13l-3 8.5h3l1.5-4.5L18 21.5h3L18 13h-3zM6 13l-3 8.5h3l1.5-4.5L9 21.5h3L9 13H6z" fill="#6001D1"/></svg>
                                <span>Yahoo</span>
                            </button>
                            <button type="button" @click="provider='imap'; applyPreset('imap')"
                                class="flex flex-col items-center gap-1 px-3 py-3 rounded-lg border-2 transition text-sm font-medium"
                                :class="provider === 'imap' ? 'border-[#25D366] bg-[#25D366]/5 text-gray-900' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.75L4.5 7.5l3-3.75h12.75l3 3.75m0 0a4.5 4.5 0 01.9 2.75"/></svg>
                                <span>IMAP</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="email" :value="__('Email address')" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="display_name" :value="__('Display name (optional — shown when you send)')" />
                        <x-text-input id="display_name" name="display_name" type="text" class="mt-1 block w-full" :value="old('display_name')" />
                    </div>

                    <fieldset class="border border-gray-200 rounded-md p-4">
                        <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Incoming (IMAP)') }}</legend>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-input-label for="imap_host" :value="__('Host')" />
                                <x-text-input id="imap_host" name="imap_host" type="text" class="mt-1 block w-full" :value="old('imap_host')" placeholder="imap.example.com" required />
                                <x-input-error :messages="$errors->get('imap_host')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="imap_port" :value="__('Port')" />
                                <x-text-input id="imap_port" name="imap_port" type="number" class="mt-1 block w-full" :value="old('imap_port', 993)" required />
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-input-label for="imap_encryption" :value="__('Encryption')" />
                            <select id="imap_encryption" name="imap_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @foreach(['ssl', 'tls', 'starttls', 'none'] as $enc)
                                    <option value="{{ $enc }}" @selected(old('imap_encryption', 'ssl') === $enc)>{{ strtoupper($enc) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>

                    <fieldset class="border border-gray-200 rounded-md p-4">
                        <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Outgoing (SMTP)') }}</legend>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-input-label for="smtp_host" :value="__('Host')" />
                                <x-text-input id="smtp_host" name="smtp_host" type="text" class="mt-1 block w-full" :value="old('smtp_host')" placeholder="smtp.example.com" required />
                                <x-input-error :messages="$errors->get('smtp_host')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="smtp_port" :value="__('Port')" />
                                <x-text-input id="smtp_port" name="smtp_port" type="number" class="mt-1 block w-full" :value="old('smtp_port', 587)" required />
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-input-label for="smtp_encryption" :value="__('Encryption')" />
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @foreach(['ssl', 'tls', 'starttls', 'none'] as $enc)
                                    <option value="{{ $enc }}" @selected(old('smtp_encryption', 'tls') === $enc)>{{ strtoupper($enc) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="username" :value="__('Username (usually your full email)')" />
                            <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username')" autocomplete="off" required />
                            <x-input-error :messages="$errors->get('username')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password" :value="__('Password / App password')" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        {{ __('We sign in with these credentials to verify them before saving, and store them encrypted. For Gmail, use an') }}
                        <a href="https://support.google.com/accounts/answer/185833" target="_blank" class="text-[#25D366] underline">{{ __('app password') }}</a>.
                        {{ __('For Outlook / Microsoft 365, IMAP and SMTP AUTH must be enabled on the mailbox — many tenants disable basic auth, so you may need an app password or an admin to turn it on.') }}
                    </p>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <a href="{{ route('mailbox.accounts.index') }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</a>
                        <button type="submit" class="px-5 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-md hover:bg-[#1da851]">{{ __('Connect') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
