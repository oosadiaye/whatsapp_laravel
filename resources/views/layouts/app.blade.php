<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

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
                  <header> — topbar with hamburger + page heading
                  <main> — page content
            The lg:pl-64 leaves room for the 256px-wide fixed sidebar on desktop;
            on mobile the sidebar overlays via z-index and pl-0 keeps content full-width.
        --}}

        @include('layouts.navigation')

        <div class="lg:pl-64 min-h-screen flex flex-col">

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
