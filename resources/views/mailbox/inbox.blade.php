<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Mailbox') }}</h2>
            <a href="{{ route('mailbox.accounts.index') }}" class="text-sm text-gray-500 hover:text-gray-700">{{ __('Accounts') }}</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @livewire('mailbox.inbox')
        </div>
    </div>
</x-app-layout>
