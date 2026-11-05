<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">New lead profile</h2>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 lg:px-8">
        <form method="POST" action="{{ route('lead-profiles.store') }}" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @include('lead-profiles._form', ['profile' => null])
            <div class="mt-6 flex gap-3">
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Create and build search</button>
                <a href="{{ route('lead-profiles.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
