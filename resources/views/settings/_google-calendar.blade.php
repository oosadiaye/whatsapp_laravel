{{-- Google Calendar / Meet — OAuth client for the Meetings feature. Rendered INSIDE the settings form. --}}
@php
    $gcClientId = (string) ($settings['google_client_id'] ?? '');
    $gcSecretSet = (string) ($settings['google_client_secret'] ?? '') !== '';
@endphp
<div x-data="{ showSecret: false, copied: false }" class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
        <span class="grid place-items-center w-9 h-9 rounded-lg bg-blue-50 text-blue-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        </span>
        <div>
            <h3 class="text-base font-bold text-gray-900">{{ __('Google Calendar & Meet') }}</h3>
            <p class="text-xs text-gray-500">{{ __('Create Google Meet meetings the moment you schedule them, and show your Google Calendar on the Meetings page. Free: no API charges.') }}</p>
        </div>
    </div>

    <div class="p-6 space-y-5">
        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-gray-800">
            <p class="font-medium text-gray-900">Create the Google OAuth client (about 5 minutes, one time)</p>
            <p class="mt-1 text-xs text-gray-600">Sign in to Google Cloud with the account that should own the app, then follow the steps. Each link opens in a new tab.</p>
            <ol class="mt-3 list-decimal space-y-2 pl-5">
                <li>
                    <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 underline">Create a Google Cloud project</a>
                    <span class="text-gray-600">(or pick one you already have).</span>
                </li>
                <li>
                    <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 underline">Enable the Google Calendar API</a>
                    <span class="text-gray-600">&mdash; press <strong>Enable</strong>.</span>
                </li>
                <li>
                    <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 underline">Set up the OAuth consent screen</a>
                    <span class="text-gray-600">&mdash; choose <em>Internal</em> if everyone is in your Google Workspace, otherwise <em>External</em>. Enter an app name and your email, add the scope <span class="font-data">.../auth/calendar.events</span>, and (External, testing mode) add each person who will connect under <em>Test users</em>, up to 100.</span>
                </li>
                <li>
                    <a href="https://console.cloud.google.com/apis/credentials/oauthclient" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 underline">Create the OAuth client ID</a>
                    <span class="text-gray-600">&mdash; type <strong>Web application</strong>. Under <em>Authorized redirect URIs</em> paste the address shown below, exactly.</span>
                </li>
                <li class="text-gray-600">Copy the <strong>Client ID</strong> and <strong>Client secret</strong> Google shows into the boxes below, then press <strong>Save Settings</strong>.</li>
            </ol>
            <p class="mt-3 text-xs text-gray-600">
                Already have a project? <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 underline">Open your credentials list</a>.
            </p>
        </div>

        <div>
            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Authorized redirect URI (register this in Google Cloud)') }}</label>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ \App\Services\Google\GoogleConfig::redirectUri() }}" id="google-redirect-uri" title="{{ \App\Services\Google\GoogleConfig::redirectUri() }}"
                       class="block w-full rounded-lg border-gray-300 bg-gray-50 text-sm font-data shadow-sm">
                <button type="button"
                        @click="navigator.clipboard.writeText(document.getElementById('google-redirect-uri').value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
            @if(str_starts_with(\App\Services\Google\GoogleConfig::redirectUri(), 'http://') && ! str_contains(\App\Services\Google\GoogleConfig::redirectUri(), '127.0.0.1') && ! str_contains(\App\Services\Google\GoogleConfig::redirectUri(), 'localhost'))
                <p class="mt-1 text-xs text-amber-600">This address starts with http://. Google only accepts https:// (except for localhost). Set APP_URL to your https address.</p>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="google_client_id" class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Client ID') }}</label>
                <input id="google_client_id" type="text" name="google_client_id" value="{{ old('google_client_id', $gcClientId) }}" maxlength="255" autocomplete="off" spellcheck="false"
                       placeholder="1234567890-abc.apps.googleusercontent.com"
                       class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                @error('google_client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="google_client_secret" class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ __('Client secret') }}</label>
                <div class="relative">
                    <input id="google_client_secret" :type="showSecret ? 'text' : 'password'" name="google_client_secret" maxlength="512" autocomplete="new-password" spellcheck="false"
                           placeholder="{{ $gcSecretSet ? '•••••••• (saved — leave blank to keep)' : 'GOCSPX-…' }}"
                           class="block w-full rounded-lg border-gray-300 pr-16 text-sm shadow-sm focus:border-brand focus:ring-brand">
                    <button type="button" @click="showSecret = !showSecret" class="absolute inset-y-0 right-0 px-3 text-xs text-gray-500 hover:text-gray-800" x-text="showSecret ? 'Hide' : 'Show'"></button>
                </div>
                @error('google_client_secret')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">{{ __('Your connection') }}</h4>
            @include('meetings._google-status', ['return' => 'settings'])
        </div>
    </div>
</div>
