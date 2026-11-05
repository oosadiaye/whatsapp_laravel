@php
    $eventLabels = [
        'pulled' => 'Pulled from the platform',
        'qualified' => 'Qualified',
        'rejected' => 'Rejected',
        'revealed' => 'Email revealed',
        'reveal_empty' => 'No email available',
        'qualify_failed' => 'AI could not judge this lead',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $lead->name }}</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-4 lg:px-8 space-y-6">
        <a href="{{ route('leads.index') }}" class="text-sm text-brand-text hover:underline">&larr; All leads</a>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="lead-details">
            <h3 id="lead-details" class="font-display text-lg font-bold text-gray-900">Details</h3>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                <dt class="font-medium text-gray-600">Title</dt><dd class="text-gray-900">{{ $lead->title ?? '—' }}</dd>
                <dt class="font-medium text-gray-600">Company</dt><dd class="text-gray-900">{{ $lead->company ?? '—' }}</dd>
                <dt class="font-medium text-gray-600">Email</dt>
                <dd class="text-gray-900">{{ $lead->email ?? 'Not revealed' }} @if($lead->email_status && $lead->email) <span class="text-xs text-gray-500">({{ $lead->email_status }})</span> @endif</dd>
                <dt class="font-medium text-gray-600">Status</dt><dd class="text-gray-900">{{ ucfirst($lead->status) }}</dd>
                <dt class="font-medium text-gray-600">Source</dt><dd class="text-gray-900">{{ ucfirst($lead->source) }}</dd>
                <dt class="font-medium text-gray-600">Profile</dt><dd class="text-gray-900">{{ $lead->profile?->name ?? '—' }}</dd>
                @if($lead->score !== null)
                    <dt class="font-medium text-gray-600">Score</dt><dd class="text-gray-900 tabular-nums">{{ $lead->score }} ({{ ucfirst((string) $lead->tier) }})</dd>
                @endif
                @if($lead->qualification_reason)
                    <dt class="font-medium text-gray-600">Reason</dt><dd class="text-gray-900">{{ $lead->qualification_reason }}</dd>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="lead-timeline">
            <h3 id="lead-timeline" class="font-display text-lg font-bold text-gray-900">Timeline</h3>
            <ol class="mt-3 space-y-3">
                @forelse($events as $event)
                    <li class="flex gap-3 text-sm">
                        <span class="w-40 shrink-0 text-xs text-gray-500">{{ $event->created_at->format('D j M, H:i') }}</span>
                        <span class="text-gray-900">
                            {{ $eventLabels[$event->type] ?? ucfirst(str_replace('_', ' ', $event->type)) }}
                            <span class="text-xs text-gray-500">by {{ $event->actor }}</span>
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">Nothing has happened to this lead yet.</li>
                @endforelse
            </ol>
        </section>
    </div>
</x-app-layout>
