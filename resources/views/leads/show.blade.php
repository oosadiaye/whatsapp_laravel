@php
    $eventLabels = [
        'pulled' => 'Pulled from the platform',
        'qualified' => 'Qualified',
        'rejected' => 'Rejected',
        'revealed' => 'Email revealed',
        'reveal_empty' => 'No email available',
        'qualify_failed' => 'AI could not judge this lead',
        'promoted' => 'Added to Contacts',
        'draft_created' => 'AI wrote an email',
        'draft_approved' => 'Email approved',
        'draft_discarded' => 'Email discarded',
        'email_sent' => 'Email sent',
        'replied' => 'Replied',
        'classified' => 'AI read the reply',
        'escalated' => 'Passed to a person',
        'reply_resolved' => 'Reply dealt with',
        'suppressed' => 'Stopped (unsubscribed)',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $lead->name }}</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 lg:px-8 space-y-6">
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

        @if($replies->isNotEmpty())
            <section class="rounded-xl border border-emerald-300 bg-white p-6 shadow-sm" aria-labelledby="lead-replies" data-testid="lead-replies">
                <h3 id="lead-replies" class="font-display text-lg font-bold text-gray-900">Replies</h3>
                <ul class="mt-3 space-y-4">
                    @foreach($replies as $reply)
                        <li class="text-sm">
                            <p class="text-xs text-gray-500">{{ $reply->created_at->format('D j M, H:i') }}
                                @if($reply->classification) &middot; AI read it as <strong>{{ str_replace('_', ' ', $reply->classification) }}</strong> ({{ $reply->confidence }}%) @endif
                            </p>
                            @if($reply->message)
                                <blockquote class="mt-1 whitespace-pre-line border-l-2 border-gray-300 pl-3 text-gray-800">{{ \Illuminate\Support\Str::limit(\App\Support\PromptText::withoutQuotedHistory((string) ($reply->message->body_text ?: strip_tags((string) $reply->message->body_html))), 800) }}</blockquote>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($messages->isNotEmpty())
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" aria-labelledby="lead-emails" data-testid="lead-emails">
                <h3 id="lead-emails" class="font-display text-lg font-bold text-gray-900">Emails</h3>
                <ul class="mt-3 space-y-4">
                    @foreach($messages as $message)
                        <li class="text-sm">
                            <p class="text-xs text-gray-500">
                                {{ ['first' => 'First email', 'followup' => 'Follow-up '.$message->step, 'reply' => 'Answer'][$message->kind] ?? $message->kind }}
                                &middot; <strong>{{ str_replace('_', ' ', $message->status) }}</strong>
                                @if($message->sent_at) &middot; {{ $message->sent_at->format('D j M, H:i') }} @endif
                                @if(! $message->by_ai) &middot; edited by a person @endif
                            </p>
                            <p class="mt-1 font-medium text-gray-900">{{ $message->subject }}</p>
                            <p class="mt-1 whitespace-pre-line text-gray-700">{{ $message->body }}</p>
                            @if($message->failure)<p class="mt-1 text-xs text-red-700">{{ $message->failure }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

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
