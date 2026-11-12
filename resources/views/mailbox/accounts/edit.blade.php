<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Edit Mailbox') }}: {{ $account->email }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm rounded-lg p-6" id="signature">
                <h3 class="text-base font-semibold text-gray-800">{{ __('Email signature') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Yours alone: it is only used when you write from this mailbox.') }}</p>
                <form method="POST" action="{{ route('mailbox.accounts.signature', $account) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PUT')
                    <textarea name="signature" rows="6" maxlength="1000" spellcheck="true"
                        placeholder="{{ __("Best regards,\nYour name\nYour role, Company\n+234 ...") }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-brand focus:ring-brand">{{ old('signature', $account->signature) }}</textarea>
                    <x-input-error :messages="$errors->get('signature')" />
                    <label class="flex items-start gap-2 text-sm text-gray-700">
                        <input type="hidden" name="signature_auto" value="0">
                        <input type="checkbox" name="signature_auto" value="1" class="mt-0.5 rounded border-gray-300"
                            @checked(old('signature_auto', $account->signature_auto ?? true))>
                        <span>{{ __('Add it automatically to every new message, reply and forward') }}
                            <span class="block text-xs text-gray-500">{{ __('It appears in the message so you can still edit or delete it before sending.') }}</span>
                        </span>
                    </label>
                    <div class="flex justify-end">
                        <button type="submit" class="px-5 py-2 bg-brand text-white text-sm font-semibold rounded-md hover:bg-brand-hover">{{ __('Save signature') }}</button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-6" id="checking">
                <h3 class="text-base font-semibold text-gray-800">{{ __('Checking for new mail') }}</h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $account->last_synced_at ? __('Last checked :when.', ['when' => $account->last_synced_at->diffForHumans()]) : __('Not checked yet.') }}
                </p>
                @if($account->last_sync_error)
                    <p class="mt-2 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800" role="alert">{{ $account->last_sync_error }}</p>
                @endif
                <form method="POST" action="{{ route('mailbox.accounts.sync-interval', $account) }}" class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="sync_interval_minutes" :value="__('Check for new mail every')" />
                        <select id="sync_interval_minutes" name="sync_interval_minutes" class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-brand focus:ring-brand">
                            @foreach(\App\Models\EmailAccount::SYNC_INTERVALS as $minutes)
                                <option value="{{ $minutes }}" @selected((int) old('sync_interval_minutes', $account->sync_interval_minutes) === $minutes)>{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('sync_interval_minutes')" class="mt-2" />
                    </div>
                    <button type="submit" class="px-5 py-2 bg-brand text-white text-sm font-semibold rounded-md hover:bg-brand-hover">{{ __('Save') }}</button>
                </form>
            </div>

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
                            <select id="imap_encryption" name="imap_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand focus:ring-brand">
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
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand focus:ring-brand">
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
                        <button type="submit" class="px-5 py-2 bg-brand text-white text-sm font-semibold rounded-md hover:bg-brand-hover">{{ __('Save & Test') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
