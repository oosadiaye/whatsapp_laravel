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
                $bqAtVoiceToken = null;
                $bqAtClientName = null;
                try {
                    if (\App\Models\Setting::get('africastalking_virtual_number')) {
                        $bqAtVoiceToken = app(\App\Services\AfricasTalkingVoiceService::class)
                            ->generateClientToken(auth()->user());
                        $bqAtClientName = \App\Services\AfricasTalkingVoiceService::clientNameForUser((int) auth()->id());
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
                // Surfaced to the UI so a failed token mint (wrong/missing AT
                // creds, unreachable token endpoint) is visible as "Voice
                // offline" instead of calls silently failing to connect.
                $bqAtVoiceReady = $bqAtVoiceToken !== null;
            @endphp
            @if($bqAtVoiceToken)
                <meta name="at-voice-token" content="{{ $bqAtVoiceToken }}">
                <meta name="at-client-name" content="{{ $bqAtClientName }}">
            @endif
            <meta name="at-voice-ready" content="{{ $bqAtVoiceReady ? '1' : '0' }}">
        @endauth

        <title>{{ config('app.name', 'BlastIQ') }} - WhatsApp Bulk Messenger</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

                {{-- Page heading slot --}}
                <div class="flex-1 min-w-0">
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
                        <span x-data="{ ready: (document.querySelector('meta[name=at-voice-ready]')?.getAttribute('content') === '1') }"
                              x-show="!ready" x-cloak
                              class="hidden md:inline-flex items-center gap-1.5 rounded-full bg-red-100 border border-red-300 px-3 py-1 text-xs font-medium text-red-900"
                              title="Africa's Talking voice softphone is not registered — calls cannot connect. Check the Africa's Talking settings (virtual number, API key, username) and that the WebRTC token endpoint is reachable.">
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

        @livewireScripts
        @stack('scripts')
    </body>
</html>
