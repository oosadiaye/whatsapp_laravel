<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Meetings</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 lg:px-8 space-y-6">
        <livewire:meetings.calendar />
        <livewire:meetings.meeting-form />

        <section aria-labelledby="gcal-title" class="rounded-2xl border border-gray-200 bg-white p-5">
            <h3 id="gcal-title" class="mb-3 font-display text-base font-semibold text-gray-900">Google Calendar &amp; Meet</h3>
            @include('meetings._google-status', ['return' => 'meetings'])
        </section>

        @include('meetings._google-forms', ['return' => 'meetings'])
    </div>
</x-app-layout>
