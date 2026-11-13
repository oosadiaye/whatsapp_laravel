<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    {{-- ───────── Month grid ───────── --}}
    <section aria-labelledby="cal-title" class="min-w-0">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h3 id="cal-title" class="font-display text-lg font-semibold text-gray-900 mr-2">{{ $monthLabel }}</h3>

            <button type="button" wire:click="previousMonth" aria-label="Previous month"
                    class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button type="button" wire:click="today"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Today</button>
            <button type="button" wire:click="nextMonth" aria-label="Next month"
                    class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>

            <div class="ml-auto flex items-center gap-2">
                @if($canManageAll)
                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 text-sm" role="group" aria-label="Whose meetings">
                        <button type="button" wire:click="setScope('mine')" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}"
                                class="rounded-md px-3 py-1 {{ $scope === 'mine' ? 'bg-brand text-brand-ink' : 'text-gray-700 hover:bg-gray-50' }}">Mine</button>
                        <button type="button" wire:click="setScope('everyone')" aria-pressed="{{ $scope === 'everyone' ? 'true' : 'false' }}"
                                class="rounded-md px-3 py-1 {{ $scope === 'everyone' ? 'bg-brand text-brand-ink' : 'text-gray-700 hover:bg-gray-50' }}">Everyone</button>
                    </div>
                @endif
                @if($canCreate)
                    <button type="button" wire:click="newMeeting"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        New meeting
                    </button>
                @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
            <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-[11px] font-bold uppercase tracking-wide text-gray-500" aria-hidden="true">
                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
                    <div class="py-2">{{ $dow }}</div>
                @endforeach
            </div>

            @foreach($weeks as $week)
                <div class="grid grid-cols-7 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                    @foreach($week as $cell)
                        <div class="min-h-[4.5rem] border-r border-gray-100 p-1 last:border-r-0 sm:min-h-[6.5rem] sm:p-1.5 {{ $cell['inMonth'] ? '' : 'bg-gray-50' }}">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 font-data text-xs
                                    {{ $cell['isToday'] ? 'bg-brand font-bold text-brand-ink' : ($cell['inMonth'] ? 'text-gray-700' : 'text-gray-500') }}">{{ $cell['day'] }}</span>
                                @if($canCreate && $cell['inMonth'])
                                    <button type="button" wire:click="newMeeting('{{ $cell['date'] }}')" aria-label="New meeting on {{ $cell['date'] }}"
                                            class="hidden h-5 w-5 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:inline-flex">+</button>
                                @endif
                            </div>

                            <ul class="mt-1 space-y-0.5">
                                @foreach($cell['meetings']->take(3) as $m)
                                    <li>
                                        <button type="button" wire:click="openMeeting({{ $m->id }})"
                                                class="block w-full truncate rounded bg-voice-soft px-1.5 py-0.5 text-left text-[11px] font-medium text-voice hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"
                                                title="{{ $m->title }}">
                                            <span class="font-data">{{ $m->starts_at->clone()->setTimezone($tz)->format('H:i') }}</span>
                                            <span class="hidden sm:inline">{{ $m->title }}</span>
                                        </button>
                                    </li>
                                @endforeach
                                @if($cell['meetings']->count() > 3)
                                    <li class="px-1 text-[11px] text-gray-500">+{{ $cell['meetings']->count() - 3 }} more</li>
                                @endif
                                @foreach($cell['tasks']->take(2) as $task)
                                    <li>
                                        <a href="{{ route('boards.show', $task->board) }}" wire:navigate
                                           class="block truncate rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-700 hover:brightness-95"
                                           title="Task due: {{ $task->title }}">
                                            <span aria-hidden="true">✓</span><span class="sr-only">Task due:</span> <span class="hidden sm:inline">{{ $task->title }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <p class="mt-2 text-xs text-gray-500">
            Times are in {{ $tz }}. <span class="inline-block rounded bg-voice-soft px-1.5 text-voice">Meetings</span>
            <span class="inline-block rounded bg-amber-50 px-1.5 text-amber-700">Task due dates</span> are added automatically from your task board.
        </p>
    </section>

    {{-- ───────── Sidebar ───────── --}}
    <aside class="space-y-6">
        <section aria-labelledby="upcoming-title">
            <h3 id="upcoming-title" class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Upcoming</h3>
            @if($upcoming->isEmpty())
                <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">Nothing scheduled. {{ $canCreate ? 'Use “New meeting” to add one.' : '' }}</p>
            @else
                <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                    @foreach($upcoming as $m)
                        <li>
                            <button type="button" wire:click="openMeeting({{ $m->id }})" class="block w-full px-3 py-2.5 text-left hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                                <span class="block truncate text-sm font-medium text-gray-900">{{ $m->title }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $m->starts_at->clone()->setTimezone($tz)->format('D j M, H:i') }}
                                    @if($m->videoProvider()) · {{ $m->videoProvider() }} @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="team-title">
            <h3 id="team-title" class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Team right now</h3>
            <ul class="max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-xl border border-gray-200 bg-white">
                @foreach($team as $person)
                    @php
                        $inMeeting = $person['busyUntil'] !== null;
                        $dot = $inMeeting ? 'bg-voice' : match($person['presence']) { 'available' => 'bg-green-500', 'busy' => 'bg-orange-500', default => 'bg-gray-400' };
                        $label = $inMeeting ? 'In a meeting until '.$person['busyUntil']->clone()->setTimezone($tz)->format('H:i') : ucfirst($person['presence']);
                    @endphp
                    <li class="flex items-center gap-2 px-3 py-2 text-sm">
                        <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 truncate text-gray-900">{{ $person['name'] }}</span>
                        <span class="flex-shrink-0 text-xs text-gray-500">{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-gray-500">Status is set by each person (Available, Busy, Away); “In a meeting” appears by itself while a meeting runs.</p>
        </section>
    </aside>

    {{-- ───────── Meeting detail ───────── --}}
    @if($open)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
             x-data x-on:keydown.escape.window="$wire.closeMeeting()">
            <div class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-lg sm:max-w-lg sm:rounded-2xl"
                 role="dialog" aria-modal="true" aria-labelledby="meeting-title" wire:key="meeting-{{ $open->id }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h3 id="meeting-title" class="font-display text-lg font-semibold text-gray-900">{{ $open->title }}</h3>
                        <p class="text-sm text-gray-500">
                            {{ $open->starts_at->clone()->setTimezone($tz)->format('l j F Y, H:i') }} – {{ $open->ends_at->clone()->setTimezone($tz)->format('H:i') }}
                            <span class="text-xs">({{ $tz }})</span>
                        </p>
                        <p class="text-sm text-gray-500">Organised by {{ $open->organizerName() }}</p>
                    </div>
                    <button type="button" wire:click="closeMeeting" aria-label="Close"
                            class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if($open->isCancelled())
                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">This meeting was cancelled.</p>
                @endif

                @if($open->imported)
                    <p class="mt-3 rounded-lg bg-voice-soft px-3 py-2 text-sm text-voice">
                        From Google Calendar{{ $open->external_organizer ? ' (organised by '.$open->external_organizer.')' : '' }}. It is a read-only copy: change or cancel it in Google.
                        @if(filled($open->external_url))
                            <a href="{{ $open->external_url }}" target="_blank" rel="noopener noreferrer" class="font-medium underline">Open in Google Calendar</a>
                        @endif
                    </p>
                @elseif($open->isGoogleManaged())
                    <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">Kept in Google Calendar, which sends the invitations.
                        @if(filled($open->external_url))
                            <a href="{{ $open->external_url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-text underline">Open in Google Calendar</a>
                        @endif
                    </p>
                @endif

                @if(filled($open->location))
                    <p class="mt-3 text-sm text-gray-700"><span class="text-gray-500">Where:</span> {{ $open->location }}</p>
                @endif
                @if(filled($open->description))
                    <p class="mt-3 whitespace-pre-line text-sm text-gray-700">{{ $open->description }}</p>
                @endif

                @if(filled($open->video_url) && ! $open->isCancelled())
                    <a href="{{ $open->video_url }}" target="_blank" rel="noopener noreferrer"
                       class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        Join with {{ $open->videoProvider() }}
                    </a>
                @endif

                <h4 class="mt-5 text-xs font-bold uppercase tracking-wide text-gray-500">Invited ({{ $open->attendees->count() }})</h4>
                @if($open->attendees->isEmpty())
                    <p class="mt-1 text-sm text-gray-500">Only you.</p>
                @else
                    <ul class="mt-1 divide-y divide-gray-100 rounded-xl border border-gray-200">
                        @foreach($open->attendees as $a)
                            <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                <span class="min-w-0 flex-1 truncate text-gray-900">{{ $a->displayName() }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs
                                    {{ $a->response === 'accepted' ? 'bg-green-50 text-green-700' : ($a->response === 'declined' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700') }}">
                                    {{ $a->response === 'pending' ? 'No answer yet' : ucfirst($a->response) }}
                                </span>
                                @if($openCanManage && $a->invite_error)
                                    <span class="basis-full text-xs text-red-700">Invitation not delivered: {{ $a->invite_error }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($myAttendee && ! $open->isCancelled() && $open->ends_at->isFuture())
                    <div class="mt-4 flex items-center gap-2">
                        <span class="text-sm text-gray-700">Will you come?</span>
                        <button type="button" wire:click="respond({{ $open->id }}, 'accepted')" aria-pressed="{{ $myAttendee->response === 'accepted' ? 'true' : 'false' }}"
                                class="rounded-lg border px-3 py-1.5 text-sm {{ $myAttendee->response === 'accepted' ? 'border-green-600 bg-green-50 text-green-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">Yes</button>
                        <button type="button" wire:click="respond({{ $open->id }}, 'declined')" aria-pressed="{{ $myAttendee->response === 'declined' ? 'true' : 'false' }}"
                                class="rounded-lg border px-3 py-1.5 text-sm {{ $myAttendee->response === 'declined' ? 'border-red-600 bg-red-50 text-red-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">No</button>
                    </div>
                @endif

                @unless($open->isCancelled())
                    <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-4 text-sm">
                        <span class="text-gray-500">Add to calendar:</span>
                        <a href="{{ $open->googleCalendarUrl() }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-text underline">Google</a>
                        <a href="{{ $open->outlookCalendarUrl() }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-text underline">Outlook / Teams</a>
                        <a href="{{ route('meetings.ics', $open) }}" class="font-medium text-brand-text underline">Download .ics</a>
                    </div>
                @endunless

                @if($openCanManage && ! $open->isCancelled())
                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="editMeeting({{ $open->id }})"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                        <button type="button" wire:click="cancelMeeting({{ $open->id }})"
                                wire:confirm="Cancel this meeting? Everyone invited will get a cancellation email."
                                class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">Cancel meeting</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
