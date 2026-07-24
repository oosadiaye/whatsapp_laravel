<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('New Email Template') }}</h2>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
            @include('email-templates._form', ['action' => route('email-templates.store')])
        </div>
    </div>
</x-app-layout>
