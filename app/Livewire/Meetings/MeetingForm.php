<?php

declare(strict_types=1);

namespace App\Livewire\Meetings;

use App\Models\Contact;
use App\Models\GoogleConnection;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Services\Google\GoogleCalendarSync;
use App\Services\Meetings\MeetingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create / edit modal for a meeting. Video is just a pasted https link — the buttons open Google Meet or
 * Teams in a new tab so the organiser can create a room there for free and paste it back.
 */
class MeetingForm extends Component
{
    /** Saves (each may email up to 50 people) one person may make per hour. */
    private const MAX_SAVES_PER_HOUR = 30;

    public bool $show = false;

    #[Locked]
    public ?int $meetingId = null;

    public string $title = '';

    public string $description = '';

    public string $date = '';

    public string $startTime = '09:00';

    public string $endTime = '10:00';

    public string $location = '';

    public string $videoUrl = '';

    /** @var list<int|string> staff user ids */
    public array $staffIds = [];

    /** @var list<int> picked contact ids */
    public array $contactIds = [];

    public string $contactSearch = '';

    public string $externalEmails = '';

    public ?int $organizerId = null;

    /** New meetings only: create it in the organiser's Google Calendar (with a Meet link); Google sends the invitations. */
    public bool $createInGoogle = false;

    #[On('meeting-form-open')]
    public function open(?int $id = null, ?string $date = null): void
    {
        $user = auth()->user();
        abort_unless((bool) $user?->can('meetings.create'), 403);

        $this->resetValidation();
        $this->reset(['title', 'description', 'location', 'videoUrl', 'staffIds', 'contactIds', 'contactSearch', 'externalEmails']);
        $this->meetingId = null;
        $this->organizerId = $user->id;
        $this->date = $this->validDate($date) ?? Carbon::now($this->tz())->format('Y-m-d');
        $this->startTime = '09:00';
        $this->endTime = '10:00';
        // Connected people get Google creation by default: it is the whole point of connecting.
        $this->createInGoogle = GoogleConnection::usableFor($user->id) !== null;

        if ($id !== null) {
            $meeting = Meeting::with('attendees')->findOrFail($id);
            abort_unless($meeting->canBeManagedBy($user), 403);
            if ($meeting->isCancelled()) {
                $this->dispatch('notify', message: 'This meeting was cancelled, so it can no longer be edited.', type: 'error');

                return;
            }
            $this->fillFrom($meeting);
        }

        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function pickContact(int $id): void
    {
        // Resolve through the model so the usual per-owner scoping applies: a contact you cannot see cannot be picked.
        if (Contact::query()->whereKey($id)->whereNotNull('email')->exists() && ! in_array($id, $this->contactIds, true)) {
            $this->contactIds[] = $id;
        }
        $this->contactSearch = '';
    }

    public function unpickContact(int $id): void
    {
        $this->contactIds = array_values(array_diff($this->contactIds, [$id]));
    }

    public function save(MeetingService $service): void
    {
        $user = auth()->user();
        abort_unless((bool) $user?->can('meetings.create'), 403);

        $this->validate($this->rules(), [
            'videoUrl.regex' => 'The video link must start with https://',
            'endTime.after' => 'The end time must be after the start time.',
        ]);

        [$start, $end] = $this->range();
        $existing = $this->meetingId !== null ? Meeting::findOrFail($this->meetingId) : null;
        if ($existing !== null) {
            abort_unless($existing->canBeManagedBy($user), 403);
            if ($existing->isCancelled()) {
                // Cancelled in another tab or by another manager while this form was open.
                $this->show = false;
                $this->dispatch('notify', message: 'This meeting was cancelled, so your changes were not saved.', type: 'error');

                return;
            }
        } elseif ($start->lt(now()->subMinutes(5))) {
            $this->addError('date', 'That time has already passed. Pick a time from now on.');

            return;
        }

        $attendees = $this->attendeeRows();
        if ($attendees === null) {
            return; // an error was already attached to externalEmails
        }

        // Invitations go out under the company's sender name, so cap how many a person can trigger.
        $throttleKey = 'meetings-save:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_SAVES_PER_HOUR)) {
            $this->addError('title', 'You have scheduled many meetings in the last hour. Please wait a little before sending more invitations.');

            return;
        }
        RateLimiter::hit($throttleKey, 3600);

        $data = [
            'title' => trim($this->title),
            'description' => filled($this->description) ? trim($this->description) : null,
            'location' => filled($this->location) ? trim($this->location) : null,
            'video_url' => filled($this->videoUrl) ? trim($this->videoUrl) : null,
            'starts_at' => $start,
            'ends_at' => $end,
        ];

        $organizer = $existing === null ? $this->resolveOrganizer($user) : null;
        // Only your own Google Calendar: scheduling for a colleague never writes into their Google account.
        $useGoogle = $existing === null && $this->createInGoogle
            && $organizer->id === $user->id && GoogleConnection::usableFor($organizer->id) !== null;

        try {
            $meeting = $existing !== null
                ? $service->update($existing, $data, $attendees)
                : $service->create($organizer, $data, $attendees, sendInvites: ! $useGoogle);
        } catch (InvalidArgumentException $e) {
            $this->addError('externalEmails', $e->getMessage());

            return;
        }

        $warning = $service->warning;
        $googleOk = false;
        if ($useGoogle) {
            $error = app(GoogleCalendarSync::class)->push($meeting);
            if ($error === null) {
                $googleOk = true;
            } else {
                // Never leave guests uninvited: BlastIQ sends the invitations itself when Google could not.
                $service->sendInvites($meeting);
                $warning = 'Saved, but Google Calendar could not create it: '.$error.' The invitations were sent by BlastIQ instead.';
            }
        }

        $this->show = false;
        $this->dispatch('meeting-saved', id: $meeting->id);
        if ($warning !== null) {
            $this->dispatch('notify', message: $warning, type: 'error');
        } else {
            $this->dispatch('notify', message: match (true) {
                $googleOk => 'Meeting created in Google Calendar'.($meeting->videoProvider() === 'Google Meet' ? ' with a Google Meet link' : '').'. Google is sending the invitations.',
                $existing !== null => 'Meeting updated. Everyone invited has been told.',
                default => 'Meeting scheduled. Invitations are on their way.',
            }, type: 'success');
        }
    }

    public function render()
    {
        $conflicts = $this->show ? $this->conflictsNow() : [];
        $search = trim($this->contactSearch);

        return view('livewire.meetings.meeting-form', [
            'tz' => $this->tz(),
            'staff' => $this->show ? $this->staffChoices() : collect(),
            'contactResults' => $this->show && mb_strlen($search) >= 2 ? $this->searchContacts($search) : collect(),
            'pickedContacts' => $this->show && $this->contactIds !== []
                ? Contact::query()->whereIn('id', $this->contactIds)->get(['id', 'name', 'email'])
                : collect(),
            'conflicts' => $conflicts,
            'canManageAll' => (bool) auth()->user()?->can('meetings.manage_all'),
            'googleReady' => $this->show && $this->meetingId === null
                && ($this->organizerId === null || $this->organizerId === (int) auth()->id())
                && GoogleConnection::usableFor((int) auth()->id()) !== null,
            'googleManaged' => $this->show && $this->meetingId !== null
                && (Meeting::find($this->meetingId)?->isGoogleManaged() ?? false),
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'videoUrl' => ['nullable', 'string', 'max:500', 'url', 'regex:/^https:\/\//i'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'after:startTime'],
            'staffIds' => ['array', 'max:'.Meeting::MAX_ATTENDEES],
            'staffIds.*' => ['integer'],
            'externalEmails' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} UTC instants */
    private function range(): array
    {
        $tz = $this->tz();

        return [
            Carbon::createFromFormat('Y-m-d H:i', $this->date.' '.$this->startTime, $tz)->utc(),
            Carbon::createFromFormat('Y-m-d H:i', $this->date.' '.$this->endTime, $tz)->utc(),
        ];
    }

    /**
     * Staff, contacts and typed emails as service rows. Null (with an error attached) when a typed address is invalid.
     *
     * @return list<array{email: string, name: ?string, user_id: ?int, contact_id: ?int}>|null
     */
    private function attendeeRows(): ?array
    {
        $rows = [];

        $staff = User::query()->where('is_active', true)->whereIn('id', array_map('intval', $this->staffIds))->get(['id', 'name', 'email']);
        foreach ($staff as $member) {
            $rows[] = ['email' => $member->email, 'name' => $member->name, 'user_id' => $member->id, 'contact_id' => null];
        }

        foreach (Contact::query()->whereIn('id', $this->contactIds)->whereNotNull('email')->get(['id', 'name', 'email']) as $contact) {
            $rows[] = ['email' => $contact->email, 'name' => $contact->name, 'user_id' => null, 'contact_id' => $contact->id];
        }

        // Editing: someone already invited who is still ticked but no longer resolves for THIS editor (a contact
        // owned by someone else, a deactivated colleague, a contact since deleted) must stay invited. Dropping them
        // here would silently remove them and email them a cancellation for a meeting that still exists.
        if ($this->meetingId !== null) {
            $resolved = collect($rows)->pluck('email')->map(fn (string $e): string => strtolower($e))->all();
            $wantedUsers = array_map('intval', $this->staffIds);
            $wantedContacts = array_map('intval', $this->contactIds);
            foreach (MeetingAttendee::query()->where('meeting_id', $this->meetingId)->get() as $existing) {
                $stillWanted = ($existing->user_id !== null && in_array((int) $existing->user_id, $wantedUsers, true))
                    || ($existing->contact_id !== null && in_array((int) $existing->contact_id, $wantedContacts, true));
                if ($stillWanted && ! in_array(strtolower($existing->email), $resolved, true)) {
                    $rows[] = ['email' => $existing->email, 'name' => $existing->name, 'user_id' => $existing->user_id, 'contact_id' => $existing->contact_id];
                }
            }
        }

        $bad = [];
        foreach (preg_split('/[\s,;]+/', trim($this->externalEmails), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (! filter_var($token, FILTER_VALIDATE_EMAIL)) {
                $bad[] = $token;

                continue;
            }
            $rows[] = ['email' => $token, 'name' => null, 'user_id' => null, 'contact_id' => null];
        }
        if ($bad !== []) {
            $this->addError('externalEmails', 'These are not valid email addresses: '.implode(', ', array_slice($bad, 0, 5)));

            return null;
        }

        return $rows;
    }

    private function resolveOrganizer(User $user): User
    {
        if ($this->organizerId === null || $this->organizerId === $user->id || ! $user->can('meetings.manage_all')) {
            return $user;
        }

        return User::query()->where('is_active', true)->find($this->organizerId) ?? $user;
    }

    /** @return array<int, array{name: string, title: string}> user id => who clashes with what (warning only) */
    private function conflictsNow(): array
    {
        if (! $this->isValidTimeInput()) {
            return [];
        }

        try {
            [$start, $end] = $this->range();
        } catch (\Throwable) {
            return []; // half-typed or impossible time such as 25:99 — the validator reports it on save
        }
        if ($end->lte($start)) {
            return [];
        }

        $ids = array_map('intval', $this->staffIds);
        $organizer = $this->organizerId ?? (int) auth()->id();
        $ids[] = $organizer;

        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $out = [];
        foreach (app(MeetingService::class)->conflicts($ids, $start, $end, $this->meetingId) as $userId => $meeting) {
            // Another person's meeting title is private; only the viewer's own is named.
            $own = $meeting->canBeViewedBy(auth()->user());
            $out[$userId] = [
                'name' => (string) ($names[$userId] ?? 'Someone'),
                'title' => $own ? $meeting->title : 'another meeting',
            ];
        }

        return $out;
    }

    private function isValidTimeInput(): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)
            && (bool) preg_match('/^\d{2}:\d{2}$/', $this->startTime)
            && (bool) preg_match('/^\d{2}:\d{2}$/', $this->endTime)
            && $this->validDate($this->date) !== null;
    }

    private function validDate(?string $date): ?string
    {
        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date, $this->tz())->format('Y-m-d') === $date ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fillFrom(Meeting $meeting): void
    {
        $tz = $this->tz();
        $start = $meeting->starts_at->clone()->setTimezone($tz);
        $end = $meeting->ends_at->clone()->setTimezone($tz);

        $this->meetingId = $meeting->id;
        $this->organizerId = $meeting->organizer_id;
        $this->title = $meeting->title;
        $this->description = (string) $meeting->description;
        $this->location = (string) $meeting->location;
        $this->videoUrl = (string) $meeting->video_url;
        $this->date = $start->format('Y-m-d');
        $this->startTime = $start->format('H:i');
        $this->endTime = $end->format('H:i');
        $this->staffIds = $meeting->attendees->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->contactIds = $meeting->attendees->whereNotNull('contact_id')->pluck('contact_id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->externalEmails = $meeting->attendees->whereNull('user_id')->whereNull('contact_id')->pluck('email')->implode(', ');
    }

    /** @return Collection<int, User> */
    private function staffChoices(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot((int) ($this->organizerId ?? auth()->id()))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);
    }

    /** @return Collection<int, Contact> */
    private function searchContacts(string $search): Collection
    {
        $like = '%'.Str::of($search)->replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'])->toString().'%';

        return Contact::query()
            ->whereNotNull('email')
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->limit(8)
            ->get(['id', 'name', 'email']);
    }

    private function tz(): string
    {
        return (string) config('app.business_timezone');
    }
}
