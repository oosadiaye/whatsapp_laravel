<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Edit Mailbox') }}: {{ $account->email }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-lg p-6">
                <form method="POST" action="{{ route('mailbox.accounts.update', $account) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <fieldset class="border border-gray-200 rounded-md p-4">
                        <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Incoming (IMAP)') }}</legend>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-input-label for="imap_host" :value="__('Host')" />
                                <x-text-input id="imap_host" name="imap_host" type="text" class="mt-1 block w-full" :value="old('imap_host', $account->credentials['imap_host'] ?? '')" required />
                                <x-input-error :messages="$errors->get('imap_host')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="imap_port" :value="__('Port')" />
                                <x-text-input id="imap_port" name="imap_port" type="number" class="mt-1 block w-full" :value="old('imap_port', $account->credentials['imap_port'] ?? 993)" required />
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-input-label for="imap_encryption" :value="__('Encryption')" />
                            <select id="imap_encryption" name="imap_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @foreach(['ssl', 'tls', 'starttls', 'none'] as $enc)
                                    <option value="{{ $enc }}" @selected((old('imap_encryption', $account->credentials['imap_encryption'] ?? 'ssl')) === $enc)>{{ strtoupper($enc) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>

                    <fieldset class="border border-gray-200 rounded-md p-4">
                        <legend class="text-sm font-semibold text-gray-700 px-1">{{ __('Outgoing (SMTP)') }}</legend>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-input-label for="smtp_host" :value="__('Host')" />
                                <x-text-input id="smtp_host" name="smtp_host" type="text" class="mt-1 block w-full" :value="old('smtp_host', $account->credentials['smtp_host'] ?? '')" required />
                                <x-input-error :messages="$errors->get('smtp_host')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="smtp_port" :value="__('Port')" />
                                <x-text-input id="smtp_port" name="smtp_port" type="number" class="mt-1 block w-full" :value="old('smtp_port', $account->credentials['smtp_port'] ?? 587)" required />
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-input-label for="smtp_encryption" :value="__('Encryption')" />
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#25D366] focus:ring-[#25D366]">
                                @foreach(['ssl', 'tls', 'starttls', 'none'] as $enc)
                                    <option value="{{ $enc }}" @selected((old('smtp_encryption', $account->credentials['smtp_encryption'] ?? 'tls')) === $enc)>{{ strtoupper($enc) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="username" :value="__('Username')" />
                            <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username', $account->credentials['username'] ?? '')" autocomplete="off" required />
                        </div>
                        <div>
                            <x-input-label for="password" :value="__('Password (leave blank to keep current)')" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <a href="{{ route('mailbox.accounts.index') }}" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</a>
                        <button type="submit" class="px-5 py-2 bg-[#25D366] text-white text-sm font-semibold rounded-md hover:bg-[#1da851]">{{ __('Save & Test') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
