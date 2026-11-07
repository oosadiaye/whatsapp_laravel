@php
    $kindLabels = ['first' => 'First email', 'followup' => 'Follow-up', 'reply' => 'Answer to a reply'];
    $classLabels = [
        'interested' => 'Interested', 'question' => 'Has a question', 'not_now' => 'Not now', 'not_interested' => 'Not interested',
        'unsubscribe' => 'Asked to stop', 'out_of_office' => 'Out of office', 'other' => 'Unclear',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Replies &amp; approvals</h2>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto px-4 lg:px-8 space-y-8">
        @if($killSwitch)
            <p class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                Lead automation is paused, so approved emails will wait until you resume it on Lead Sources.
            </p>
        @endif

        <section aria-labelledby="needs-you">
            <h3 id="needs-you" class="font-display text-lg font-bold text-gray-900">Replies that need you</h3>
            <p class="mt-1 text-sm text-gray-600">Prospects who answered and whose message the AI did not (or must not) handle alone.</p>
            <ul class="mt-3 space-y-3" data-testid="reply-list">
                @forelse($replies as $reply)
                    <li class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm" data-testid="reply-card">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <a href="{{ route('leads.show', $reply->lead) }}" class="font-semibold text-gray-900 hover:underline">{{ $reply->lead->name }}</a>
                                <span class="text-sm text-gray-500">{{ $reply->lead->company }}</span>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                {{ $reply->classification ? ($classLabels[$reply->classification] ?? $reply->classification) : 'Not read yet' }}
                            </span>
                        </div>
                        @if($reply->summary)
                            <p class="mt-2 text-sm text-gray-700">{{ $reply->summary }}</p>
                        @endif
                        @if($reply->message)
                            <blockquote class="mt-2 whitespace-pre-line border-l-2 border-gray-300 pl-3 text-sm text-gray-600">{{ \Illuminate\Support\Str::limit(\App\Support\PromptText::withoutQuotedHistory((string) ($reply->message->body_text ?: strip_tags((string) $reply->message->body_html))), 500) }}</blockquote>
                        @endif
                        <form method="POST" action="{{ route('lead-approvals.resolve', $reply) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Mark as dealt with</button>
                        </form>
                    </li>
                @empty
                    <li class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">No replies are waiting for you.</li>
                @endforelse
            </ul>
        </section>

        <section aria-labelledby="to-approve">
            <h3 id="to-approve" class="font-display text-lg font-bold text-gray-900">Emails waiting for approval</h3>
            <p class="mt-1 text-sm text-gray-600">Written by the AI. Edit if you like, then approve to send or discard.</p>
            <ul class="mt-3 space-y-4" data-testid="approval-list">
                @forelse($messages as $message)
                    <li class="rounded-xl border bg-white p-4 shadow-sm {{ $message->status === 'failed' ? 'border-red-300' : 'border-gray-200' }}" data-testid="approval-card">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm">
                                <span class="font-semibold text-gray-900">{{ $kindLabels[$message->kind] ?? $message->kind }}</span>
                                to <a href="{{ route('leads.show', $message->lead) }}" class="font-medium text-gray-900 hover:underline">{{ $message->lead->name }}</a>
                                <span class="text-gray-500">&lt;{{ $message->lead->email }}&gt;</span>
                            </p>
                            @if($message->status === 'failed')
                                <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700">Could not be sent</span>
                            @endif
                        </div>
                        @if($message->status === 'failed' && $message->failure)
                            <p class="mb-2 text-sm text-red-700">{{ $message->failure }}</p>
                        @endif
                        <form method="POST" action="{{ route('lead-approvals.approve', $message) }}" class="space-y-2">
                            @csrf
                            <label class="sr-only" for="subject-{{ $message->id }}">Subject</label>
                            <input id="subject-{{ $message->id }}" type="text" name="subject" value="{{ $message->subject }}" maxlength="150"
                                   class="w-full rounded-lg border-gray-300 text-sm font-medium shadow-sm focus:border-brand focus:ring-brand">
                            <label class="sr-only" for="body-{{ $message->id }}">Message</label>
                            <textarea id="body-{{ $message->id }}" name="body" rows="7" maxlength="1500"
                                      class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">{{ $message->body }}</textarea>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                    {{ $message->status === 'failed' ? 'Try sending again' : 'Approve and send' }}
                                </button>
                                <button type="submit" form="discard-{{ $message->id }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Discard</button>
                            </div>
                        </form>
                        {{-- Separate form: nesting it inside the one above would be discarded by browsers. --}}
                        <form id="discard-{{ $message->id }}" method="POST" action="{{ route('lead-approvals.discard', $message) }}">@csrf</form>
                    </li>
                @empty
                    <li class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">Nothing is waiting for approval.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-app-layout>
