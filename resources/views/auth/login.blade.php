<x-guest-layout>
    {{-- Heading --}}
    <header class="mb-8">
        <h2 class="text-2xl lg:text-3xl font-semibold tracking-tight text-gray-900">{{ __('Welcome back') }}</h2>
        <p class="mt-1.5 text-sm text-gray-500">{{ __('Sign in to your BlastIQ workspace.') }}</p>
    </header>

    {{-- Session status (e.g. "Password reset link sent") --}}
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        {{-- Email --}}
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('Email address') }}</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8l-9 6-9-6m18 0v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8m18 0V6a2 2 0 00-2-2H5a2 2 0 00-2 2v2"/>
                    </svg>
                </span>
                <input id="email" name="email" type="email" required autofocus autocomplete="username"
                       value="{{ old('email') }}"
                       placeholder="you@company.com"
                       class="block w-full pl-10 pr-3 py-2.5 text-sm border-gray-200 rounded-lg shadow-sm placeholder:text-gray-400
                              focus:border-emerald-500 focus:ring-emerald-500 focus:ring-2 focus:ring-offset-0
                              transition" />
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        {{-- Password --}}
        <div x-data="{ show: false }">
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}"
                       class="text-xs font-medium text-emerald-700 hover:text-emerald-900 underline-offset-2 hover:underline">
                        {{ __('Forgot password?') }}
                    </a>
                @endif
            </div>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </span>
                <input id="password" name="password" required autocomplete="current-password"
                       :type="show ? 'text' : 'password'"
                       placeholder="••••••••"
                       class="block w-full pl-10 pr-11 py-2.5 text-sm border-gray-200 rounded-lg shadow-sm placeholder:text-gray-400
                              focus:border-emerald-500 focus:ring-emerald-500 focus:ring-2 focus:ring-offset-0
                              transition" />
                <button type="button" @click="show = !show"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 transition"
                        :aria-label="show ? 'Hide password' : 'Show password'">
                    <svg x-show="!show" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <svg x-show="show" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                    </svg>
                </button>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        {{-- Remember me --}}
        <label for="remember_me" class="flex items-center gap-2 select-none cursor-pointer">
            <input id="remember_me" name="remember" type="checkbox"
                   class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500 focus:ring-offset-0" />
            <span class="text-sm text-gray-600">{{ __('Keep me signed in for 30 days') }}</span>
        </label>

        {{-- Submit --}}
        <button type="submit"
                class="group relative w-full inline-flex items-center justify-center px-5 py-2.5 rounded-lg
                       bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800
                       text-white text-sm font-medium tracking-tight
                       shadow-sm shadow-emerald-600/20
                       focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500
                       transition">
            <span>{{ __('Sign in') }}</span>
            <svg class="w-4 h-4 ml-2 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
            </svg>
        </button>
    </form>

</x-guest-layout>
