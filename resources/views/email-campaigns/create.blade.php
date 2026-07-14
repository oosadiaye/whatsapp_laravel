<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('New Email Campaign') }}</h2>
    </x-slot>
    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        @include('email-campaigns._form', ['campaign' => null, 'groups' => $groups])
    </div>
</x-app-layout>
