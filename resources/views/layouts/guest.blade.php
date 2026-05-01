<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('Sign in') }} · {{ config('app.name', 'BlastIQ') }}</title>
    <meta name="description" content="BlastIQ — WhatsApp Business messaging at scale. Bulk campaigns, two-way conversations, and Meta Cloud calling on one platform.">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Page-local style: WhatsApp-style chat-paper texture for the brand panel.
         Uses a CSS-only repeating dot pattern so it survives without an image asset. --}}
    <style>
        .bq-paper {
            background-color: #064e3b;
            background-image:
                radial-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                radial-gradient(rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 24px 24px;
            background-position: 0 0, 12px 12px;
        }
        .bq-grain::before {
            content: "";
            position: absolute; inset: 0;
            background-image:
                radial-gradient(at 20% 30%, rgba(16, 185, 129, 0.35) 0%, transparent 45%),
                radial-gradient(at 85% 75%, rgba(5, 150, 105, 0.30) 0%, transparent 50%);
            pointer-events: none;
        }
    </style>
</head>
<body class="font-sans antialiased text-gray-900 bg-white">
    <div class="min-h-screen lg:grid lg:grid-cols-12">

        {{-- LEFT: brand panel (hidden below lg, shown as slim header otherwise) --}}
        <aside class="relative overflow-hidden bq-paper lg:col-span-7 xl:col-span-7 px-8 py-10 lg:px-14 lg:py-14 flex flex-col justify-between text-emerald-50">
            <div class="bq-grain"></div>

            {{-- Brand wordmark --}}
            <div class="relative flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-400/20 ring-1 ring-emerald-300/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-200" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <span class="text-xl font-semibold tracking-tight text-white">BlastIQ</span>
            </div>

            {{-- Headline + chat preview decoration --}}
            <div class="relative mt-12 lg:mt-0 max-w-lg">
                <h1 class="text-3xl lg:text-4xl xl:text-5xl font-semibold text-white leading-tight tracking-tight">
                    WhatsApp at scale.<br/>
                    <span class="text-emerald-300">Without the spreadsheet.</span>
                </h1>
                <p class="mt-5 text-emerald-100/80 text-base lg:text-lg leading-relaxed">
                    Run bulk campaigns, manage two-way conversations, and place Meta Cloud calls — all from one inbox your whole team can share.
                </p>

                {{-- Decorative WhatsApp-style chat bubbles --}}
                <div class="hidden lg:block mt-10 space-y-2 max-w-sm">
                    <div class="flex justify-start">
                        <div class="bg-white/95 text-gray-800 text-sm rounded-2xl rounded-tl-md px-4 py-2 shadow-lg">
                            Hi! Is the Lagos delivery still on for Friday?
                            <div class="text-[10px] text-gray-400 mt-1 text-right">10:24</div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <div class="bg-[#dcf8c6] text-gray-800 text-sm rounded-2xl rounded-tr-md px-4 py-2 shadow-lg">
                            Yes — dispatched this morning. Tracking: BL-2841 ✅
                            <div class="flex items-center justify-end gap-1 text-[10px] text-emerald-600 mt-1">
                                10:25
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 16 16"><path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425a.247.247 0 0 1 .02-.022Z"/><path d="M.94 6.22a.75.75 0 0 1 1.06.04l3.05 3.05a.75.75 0 1 1-1.06 1.06L.94 7.28a.75.75 0 0 1 0-1.06Z"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-start">
                        <div class="bg-white/95 text-gray-800 text-sm rounded-2xl rounded-tl-md px-4 py-2 shadow-lg">
                            Perfect. Thank you! 🙏
                            <div class="text-[10px] text-gray-400 mt-1 text-right">10:25</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Footer stats / proof points --}}
            <div class="relative mt-12 lg:mt-0 grid grid-cols-3 gap-6 max-w-md text-emerald-100/90">
                <div>
                    <div class="text-2xl font-semibold text-white">10k+</div>
                    <div class="text-xs uppercase tracking-wider text-emerald-200/70 mt-1">Messages / day</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-white">99.9%</div>
                    <div class="text-xs uppercase tracking-wider text-emerald-200/70 mt-1">Delivery uptime</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold text-white">SOC2</div>
                    <div class="text-xs uppercase tracking-wider text-emerald-200/70 mt-1">In progress</div>
                </div>
            </div>
        </aside>

        {{-- RIGHT: form panel --}}
        <main class="lg:col-span-5 xl:col-span-5 flex items-center justify-center px-6 py-12 lg:py-16 bg-gradient-to-b from-gray-50 to-white">
            <div class="w-full max-w-md">
                {{-- Mobile-only mini brand --}}
                <a href="/" class="lg:hidden inline-flex items-center gap-2 mb-8 text-emerald-700">
                    <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <span class="font-semibold tracking-tight">BlastIQ</span>
                </a>

                {{ $slot }}

                {{-- Footer link cluster --}}
                <p class="mt-10 text-center text-xs text-gray-400">
                    &copy; {{ date('Y') }} BlastIQ.
                    <a href="#" class="text-gray-500 hover:text-gray-700 underline-offset-2 hover:underline">Privacy</a>
                    <span aria-hidden="true">·</span>
                    <a href="#" class="text-gray-500 hover:text-gray-700 underline-offset-2 hover:underline">Terms</a>
                </p>
            </div>
        </main>
    </div>
</body>
</html>
