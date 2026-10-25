<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Theme bootstrap: saved choice, else the OS preference, applied before first paint. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            try {
                var saved = localStorage.getItem('bq-theme');
                var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    <title>{{ $title ?? __('Sign in') }} · {{ config('app.name', 'BlastIQ') }}</title>
    <meta name="description" content="BlastIQ — WhatsApp Business messaging at scale. Bulk campaigns, two-way conversations, and Meta Cloud calling on one platform.">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|bricolage-grotesque:600,700,800|jetbrains-mono:400,500&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Page-local style for the brand panel. Always a deep, dark green field (in both
         themes) so the white headline keeps its contrast; the faint grid is pure CSS. --}}
    <style>
        .bq-panel {
            background-color: #08130F;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .bq-glow::before {
            content: "";
            position: absolute; inset: 0;
            background:
                radial-gradient(60% 50% at 15% 20%, rgba(61, 220, 138, 0.20) 0%, transparent 70%),
                radial-gradient(50% 45% at 90% 85%, rgba(157, 176, 255, 0.14) 0%, transparent 70%);
            pointer-events: none;
        }
    </style>
</head>
<body class="font-sans antialiased text-gray-900">
    <div class="min-h-screen lg:grid lg:grid-cols-12">

        {{-- LEFT: brand panel (stacks above the form below lg) --}}
        <aside class="relative overflow-hidden bq-panel bq-glow lg:col-span-7 px-8 py-10 lg:px-14 lg:py-14 flex flex-col justify-between text-gray-100">

            {{-- Brand wordmark --}}
            <div class="relative flex items-center gap-3">
                <span class="grid place-items-center w-10 h-10 rounded-xl" style="background:#3DDC8A; color:#04150D;">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>
                </span>
                <span class="font-display text-2xl font-bold tracking-tight text-white">BlastIQ</span>
            </div>

            {{-- Headline + illustration --}}
            <div class="relative mt-12 lg:mt-0 max-w-lg">
                <h1 class="font-display text-4xl lg:text-5xl xl:text-6xl font-bold text-white leading-[1.04] tracking-tight">
                    One desk for every customer conversation.
                </h1>
                <p class="mt-5 text-base lg:text-lg leading-relaxed" style="color:#B5C9C0;">
                    Run bulk campaigns, answer chats as a team, and take calls in the browser — all from one inbox your whole team can share.
                </p>

                {{-- Illustration: a conversation and a live call, to show what the product does.
                     Decorative sample content, not real data. --}}
                <div class="hidden lg:block mt-10 max-w-sm space-y-3" aria-hidden="true">
                    <div class="rounded-2xl rounded-bl-md px-4 py-2.5 text-sm" style="background:#12231D; color:#E4EFEA; border:1px solid #1F3A31;">
                        Hi! Is the Lagos delivery still on for Friday?
                    </div>
                    <div class="ml-auto w-fit rounded-2xl rounded-br-md px-4 py-2.5 text-sm font-medium" style="background:#3DDC8A; color:#04150D;">
                        Yes — dispatched this morning.
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl px-4 py-3" style="background:#17302A; border:1px solid #2E5248; box-shadow:0 18px 44px rgba(0,0,0,.45);">
                        <span class="relative grid place-items-center w-10 h-10 rounded-full text-sm font-bold" style="background:#3DDC8A; color:#04150D;">
                            CO
                            <span class="absolute -inset-1 rounded-full" style="border:2px solid #3DDC8A; opacity:.4;"></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-white">Live call</span>
                            <span class="block text-xs" style="color:#A5C2B8;">Connected in the browser</span>
                        </span>
                        <span class="font-data text-lg text-white">02:14</span>
                    </div>
                </div>
            </div>

            {{-- What it does --}}
            <ul class="relative mt-12 lg:mt-0 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3 max-w-xl text-sm" style="color:#C9DAD3;">
                <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full" style="background:#3DDC8A"></span>Bulk campaigns</li>
                <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full" style="background:#3DDC8A"></span>Shared inbox</li>
                <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full" style="background:#9DB0FF"></span>Browser calls</li>
                <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full" style="background:#9DB0FF"></span>Email &amp; tasks</li>
            </ul>
        </aside>

        {{-- RIGHT: form panel --}}
        <main class="lg:col-span-5 relative flex items-center justify-center px-6 py-12 lg:py-16 bg-gray-50">
            {{-- Theme toggle (guests can pick light/dark too) --}}
            <button type="button"
                    x-data="{ dark: document.documentElement.classList.contains('dark') }"
                    @click="dark = window.bqTheme.toggle()"
                    class="absolute top-5 right-5 inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 bg-white text-gray-600 hover:text-gray-900"
                    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
                <svg x-show="!dark" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z"/></svg>
                <svg x-show="dark" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16a4 4 0 100-8 4 4 0 000 8zM12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6L7 7M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></svg>
            </button>

            <div class="w-full max-w-md">
                {{-- Mobile-only mini brand --}}
                <a href="/" class="lg:hidden inline-flex items-center gap-2 mb-8 text-gray-900">
                    <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand text-brand-ink">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>
                    </span>
                    <span class="font-display text-lg font-bold tracking-tight">BlastIQ</span>
                </a>

                {{ $slot }}

                {{-- Footer link cluster --}}
                <p class="mt-10 text-center text-xs text-gray-500">
                    &copy; {{ date('Y') }} BlastIQ.
                    <a href="#" class="text-gray-600 hover:text-gray-900 underline-offset-2 hover:underline">Privacy</a>
                    <span aria-hidden="true">·</span>
                    <a href="#" class="text-gray-600 hover:text-gray-900 underline-offset-2 hover:underline">Terms</a>
                </p>
            </div>
        </main>
    </div>
</body>
</html>
