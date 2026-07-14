<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Edit Email Campaign') }}: {{ $campaign->name }}</h2>
    </x-slot>
    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8">
        @include('email-campaigns._form', ['campaign' => $campaign, 'groups' => $groups])
    </div>
</x-app-layout>
