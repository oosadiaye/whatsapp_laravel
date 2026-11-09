{{-- "Install BlastIQ" modal. Logic: resources/js/pwa-install.js (bqInstallPrompt). Shown once the browser
     says the app is installable (or on iOS, which needs manual steps); "Not now" snoozes it for 7 days. --}}
<div x-data="bqInstallPrompt()" x-cloak
     x-show="open" x-transition.opacity
     @keydown.escape.window="open && later()"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 overflow-y-auto bg-gray-900/60"
     role="dialog" aria-modal="true" aria-labelledby="bq-install-title"
     @click.self="later()">
    <div class="relative w-full max-w-sm my-auto rounded-2xl bg-white p-6 text-center shadow-2xl ring-1 ring-gray-200"
         x-show="open" x-transition.scale.origin.center>
        <button type="button" @click="later()" aria-label="{{ __('Close') }}"
                class="absolute right-3 top-3 rounded-full p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
        </button>

        <img src="/icons/icon-192.png" alt="" width="84" height="84"
             class="mx-auto h-20 w-20 rounded-2xl bg-white shadow ring-1 ring-gray-200">

        <h2 id="bq-install-title" class="mt-4 text-lg font-semibold text-gray-900">{{ __('Install BlastIQ') }}</h2>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('Open it like an app from your home screen or desktop, in its own window, with one tap.') }}
        </p>

        {{-- Chrome / Edge / Android: the browser can show its own install dialog. --}}
        <template x-if="canPrompt">
            <div class="mt-5 space-y-2">
                <button type="button" @click="install()"
                        class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                    {{ __('Install app') }}
                </button>
                <button type="button" @click="later()" class="w-full rounded-lg px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-800">
                    {{ __('Not now') }}
                </button>
            </div>
        </template>

        {{-- iOS Safari: no install event exists, so show the manual steps. --}}
        <template x-if="! canPrompt && ios">
            <div class="mt-5 text-left">
                <ol class="space-y-3 text-sm text-gray-700">
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-text">1</span>
                        <span>{{ __('Tap the') }} <strong>{{ __('Share') }}</strong> {{ __('button in Safari (the square with an arrow, at the bottom or top of the screen).') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-text">2</span>
                        <span>{{ __('Scroll down and tap') }} <strong>{{ __('Add to Home Screen') }}</strong>.</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-text">3</span>
                        <span>{{ __('Tap') }} <strong>{{ __('Add') }}</strong>. {{ __('BlastIQ now opens from your home screen.') }}</span>
                    </li>
                </ol>
                <button type="button" @click="later()"
                        class="mt-5 w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-700">
                    {{ __('Got it') }}
                </button>
            </div>
        </template>
    </div>
</div>
