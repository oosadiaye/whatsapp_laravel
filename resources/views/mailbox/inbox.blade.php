<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mailbox') }}</h2>
            <a href="{{ route('mailbox.accounts.index') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-[#25D366] bg-[#25D366]/10 text-[#1da851] text-sm font-semibold hover:bg-[#25D366] hover:text-white transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                </svg>
                {{ __('Email accounts') }}
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @livewire('mailbox.inbox')
        </div>
    </div>
</x-app-layout>
