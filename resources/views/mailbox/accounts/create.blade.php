<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Connect a Mailbox (IMAP / SMTP)') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-lg p-6">
                <form method="POST" action="{{ route('mailbox.accounts.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="email" :value="__('Email address')" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="display_name" :value="__('Display name (optional)')" />
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
                                <x-input-error :messages="$errors->get('imap_port')" class="mt-2" />
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
                                <x-text-input id="smtp_port" name="smtp_port" type="number" class="mt-1 block w-full" :value="old('smtp_port', 465)" required />
                                <x-input-error :messages="$errors->get('smtp_port')" class="mt-2" />
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-input-label for="smtp_encryption" :value="__('Encryption')" />
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @foreach(['ssl', 'tls', 'starttls', 'none'] as $enc)
                                    <option value="{{ $enc }}" @selected(old('smtp_encryption', 'ssl') === $enc)>{{ strtoupper($enc) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="username" :value="__('Username')" />
                            <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username')" autocomplete="off" required />
                            <x-input-error :messages="$errors->get('username')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password" :value="__('Password')" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">{{ __('We sign in with these credentials to verify them before saving, and store them encrypted.') }}</p>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <a href="{{ route('mailbox.accounts.index') }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</a>
                        <button type="submit" class="px-5 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-md hover:bg-[#1da851]">{{ __('Connect') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
