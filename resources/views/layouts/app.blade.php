<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @auth
            <meta name="user-id" content="{{ auth()->id() }}">
            {{-- Africa's Talking WebRTC softphone registration. Minting a real
                 capability token here (cached 6h) lets the browser client come
                 ONLINE on page load, so an inbound call AT bridges to this agent
                 reaches a ready client instead of racing banner-time setup.
                 Swallows any failure so a misconfigured/unreachable voice
                 provider never blocks page render — calls just won't register. --}}
            @php
                // "Configured" = the operator has entered all three AT credentials
                // (the same signal the Settings → Voice integration panel shows as
                // "Configured ✓"). The softphone status pill keys off THIS — so
                // configuring AT flips it online immediately, and a slow/failed
                // capability-token request at render time can no longer wrongly
                // show "Voice offline" for a properly configured account.
                $bqAtVoiceConfigured = filled(\App\Models\Setting::get('africastalking_username'))
                    && filled(\App\Models\Setting::get('africastalking_virtual_number'))
                    && filled(\App\Models\Setting::getEncrypted('africastalking_api_key'));

                $bqAtVoiceToken = null;
                $bqAtClientName = null;
                if ($bqAtVoiceConfigured) {
                    // Best-effort: mint the real capability token for the browser
                    // SDK. A failure here no longer forces the pill offline — the
                    // client reboots/reconnects on its own — but is still reported.
                    try {
                        $bqAtVoiceToken = app(\App\Services\AfricasTalkingVoiceService::class)
                            ->generateClientToken(auth()->user());
                        $bqAtClientName = \App\Services\AfricasTalkingVoiceService::clientNameForUser((int) auth()->id());
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                $bqAtVoiceReady = $bqAtVoiceConfigured;
            @endphp
            @if($bqAtVoiceToken)
                <meta name="at-voice-token" content="{{ $bqAtVoiceToken }}">
                <meta name="at-client-name" content="{{ $bqAtClientName }}">
            @endif
            <meta name="at-voice-ready" content="{{ $bqAtVoiceReady ? '1' : '0' }}">
            {{-- Call recording flag — the browser recorder only runs when this
                 is on (config/voice.php · VOICE_CALL_RECORDING_ENABLED). --}}
            <meta name="bq-recording-enabled" content="{{ \App\Support\VoiceConfig::recordingEnabled() ? '1' : '0' }}">
            {{-- WebRTC ICE servers (STUN + optional TURN) — one server-side
                 source of truth for both call paths. See App\Support\VoiceIce. --}}
            <meta name="bq-ice-servers" content="{{ json_encode(\App\Support\VoiceIce::servers(), JSON_UNESCAPED_SLASHES) }}">
        @endauth

        <title>{{ config('app.name', 'BlastIQ') }} - WhatsApp Bulk Messenger</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        {{-- Enterprise-Modern typography for the call surfaces: Hanken Grotesk
             (display headers) + JetBrains Mono (numbers/timestamps). Applied via
             the .font-display / .font-data utilities so the rest of the app keeps
             Figtree. Same already-allowed host — no new CDN. --}}
        <link href="https://fonts.bunny.net/css?family=hanken-grotesk:500,600,700,800|jetbrains-mono:400,500&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- Self-hosted (audit M8): no third-party CDN, so no supply-chain risk
             and CSP script-src can stay same-origin. chart.js v4.5.1 pinned. --}}
        <script src="{{ asset('vendor/chart.min.js') }}"></script>
        @auth
            @if($bqAtVoiceReady)
                <script defer data-at-sdk src="{{ asset('vendor/africastalking-1.0.7.js') }}"></script>
            @endif
        @endauth
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-50">
        {{--
            Layout structure:
              <body>
                <aside> — sidebar (always visible on lg, drawer on mobile)
                <div class="lg:pl-64"> — content wrapper offset by sidebar width on desktop
                  <livewire:realtime-pulse /> — sticky call banner, lives INSIDE the
                                                content column so its full-width banner
                                                is offset by the sidebar on desktop
                  <header> — topbar with hamburger + page heading
                  <main> — page content
            The lg:pl-64 leaves room for the 256px-wide fixed sidebar on desktop;
            on mobile the sidebar overlays via z-index and pl-0 keeps content full-width.
        --}}

        @include('layouts.navigation')

        <div class="lg:pl-64 min-h-screen flex flex-col">

            {{-- Real-time UX layer (call banner + ringtone + notifications).
                 Mounted INSIDE the lg:pl-64 wrapper — earlier we had it at <body>
                 root, which made the sticky top banner extend full-viewport-width
                 with its left half disappearing under the sidebar on desktop. --}}
            @auth
                <livewire:realtime-pulse />
                {{-- Missed-call toasts (bottom-right). Reads data-missed-calls from
                     #bq-realtime-data (updated by RealtimePulse every 3s) and shows
                     a dismissable toast on delta. --}}
                @if(auth()->user()->can('conversations.call'))
                    <div x-data="bqMissedCallToast()" x-init="init()"
                         class="fixed bottom-5 right-5 z-50 flex flex-col gap-3 max-w-sm w-full pointer-events-none"
                         aria-live="polite">
                        <template x-for="(t, i) in toasts" :key="t.id">
                            <div x-show="t.visible"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-x-8"
                                 x-transition:enter-end="opacity-100 translate-x-0"
                                 x-transition:leave="transition ease-in duration-200"
                                 x-transition:leave-start="opacity-100 translate-x-0"
                                 x-transition:leave-end="opacity-0 translate-x-8"
                                 class="pointer-events-auto bg-white rounded-xl shadow-lg border border-gray-200 px-4 py-3 flex items-start gap-3">
                                <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                </svg>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-gray-900">{{ __('Missed call') }}</p>
                                    <p class="text-xs text-gray-500" x-text="t.label"></p>
                                </div>
                                <button type="button" @click="dismiss(t.id)"
                                        class="shrink-0 text-gray-400 hover:text-gray-600 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                @endif
            @endauth

            {{-- Topbar — sticky, holds mobile hamburger + page heading slot --}}
            <header class="sticky top-0 z-20 bg-white border-b border-gray-200 h-16 flex items-center px-4 sm:px-6 lg:px-8 gap-3">
                {{-- Mobile hamburger — fires the sidebar's open event --}}
                <button x-data
                        @click="$dispatch('open-sidebar')"
                        class="lg:hidden p-2 -ml-2 text-gray-500 hover:text-gray-700 rounded-md hover:bg-gray-100"
                        aria-label="Open sidebar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                {{-- Page heading slot. bq-page-title applies the Enterprise-Modern
                     display face (Hanken Grotesk) to every page's title, app-wide. --}}
                <div class="flex-1 min-w-0 bq-page-title">
                    @isset($header)
                        {{ $header }}
                    @else
                        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ config('app.name') }}</h2>
                    @endisset
                </div>

                {{-- Sound-status pill. Visible only while the AudioContext is
                     suspended (no user gesture yet OR explicit OS/browser
                     restriction). Clicking it counts as the gesture. Once
                     audio is unlocked the pill auto-hides — bqSoundIndicator
                     polls the context state every 1s. --}}
                @auth
                    <button x-data="bqSoundIndicator()" x-show="locked" x-cloak
                            @click="enable()"
                            class="hidden md:inline-flex items-center gap-1.5 rounded-full bg-amber-100 border border-amber-300 px-3 py-1 text-xs font-medium text-amber-900 hover:bg-amber-200"
                            title="Click to enable call ringtone and message notification sounds">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
                            <line x1="3" y1="3" x2="21" y2="21" stroke-linecap="round"/>
                        </svg>
                        Enable sound
                    </button>

                    @if(auth()->user()->can('conversations.call'))
                        {{-- Online when AT is configured (server flag, matches the
                             Settings panel) OR the browser softphone has actually
                             registered. Polled so it updates the moment either
                             becomes true — no page reload needed. --}}
                        <span x-data="{
                                  online: (document.querySelector('meta[name=at-voice-ready]')?.getAttribute('content') === '1'),
                                  refresh() {
                                      const configured = document.querySelector('meta[name=at-voice-ready]')?.getAttribute('content') === '1';
                                      this.online = configured || !!(window.bqVoiceClient && window.bqVoiceClient.ready);
                                  }
                              }"
                              x-init="refresh(); setInterval(() => refresh(), 2000)"
                              x-show="!online" x-cloak
                              class="hidden md:inline-flex items-center gap-1.5 rounded-full bg-red-100 border border-red-300 px-3 py-1 text-xs font-medium text-red-900"
                              title="Africa's Talking voice is not configured yet — add the username, API key, and virtual number under Settings → Voice Provider.">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                            </svg>
                            Voice offline
                        </span>
                    @endif
                @endauth
            </header>

            {{-- Flash messages — pinned just below topbar --}}
            @if(session('success') || session('error') || session('warning'))
                <div class="px-4 sm:px-6 lg:px-8 pt-4">
                    @if(session('success'))
                        <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 flex items-start gap-2"
                             x-data="{show:true}" x-show="show" x-cloak>
                            <svg class="w-5 h-5 flex-shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="flex-1">{{ session('success') }}</span>
                            <button @click="show=false" class="text-green-600 hover:text-green-800">&times;</button>
                        </div>
                    @endif
                    @if(session('warning'))
                        <div class="rounded-lg bg-yellow-50 border border-yellow-200 px-4 py-3 text-sm text-yellow-800 flex items-start gap-2"
                             x-data="{show:true}" x-show="show" x-cloak>
                            <svg class="w-5 h-5 flex-shrink-0 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span class="flex-1">{{ session('warning') }}</span>
                            <button @click="show=false" class="text-yellow-600 hover:text-yellow-800">&times;</button>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 flex items-start gap-2"
                             x-data="{show:true}" x-show="show" x-cloak>
                            <svg class="w-5 h-5 flex-shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span class="flex-1">{{ session('error') }}</span>
                            <button @click="show=false" class="text-red-600 hover:text-red-800">&times;</button>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Page content fills remaining vertical space --}}
            <main class="flex-1">
                {{ $slot }}
            </main>
        </div>

        {{-- Delegated confirm() guard for destructive forms. Replaces per-form
             onsubmit="return confirm(...)" handlers, which a nonce-based CSP
             can't allow (nonces don't cover inline event-handler attributes).
             Capture-phase delegation also covers Livewire-rendered forms. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }, true);
        </script>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
