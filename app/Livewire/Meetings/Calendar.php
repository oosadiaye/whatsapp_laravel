<?php

declare(strict_types=1);

namespace App\Livewire\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\Meetings\MeetingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The team calendar: a month grid of the viewer's meetings (plus their open task due dates), an
 * "upcoming" list, who is free right now, and a detail panel for one meeting.
 *
 * Every action re-checks permission server side (Livewire requests bypass route middleware).
 */
class Calendar extends Component
{
    private const SCOPE_MINE = 'mine';

    private const SCOPE_EVERYONE = 'everyone';

    /** First day of the displayed month, 'Y-m' in the business timezone. Server-set only. */
    #[Locked]
    public string $month = '';

    #[Locked]
    public string $scope = self::SCOPE_MINE;

    #[Locked]
    public ?int $openMeetingId = null;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->can('meetings.view'), 403);

        $this->month = $this->now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function today(): void
    {
        $this->month = $this->now()->format('Y-m');
    }

    public function setScope(string $scope): void
    {
        // Only people who may see everyone's meetings can ask for them; anyone else stays on their own.
        $this->scope = $scope === self::SCOPE_EVERYONE && auth()->user()->can('meetings.manage_all')
            ? self::SCOPE_EVERYONE
            : self::SCOPE_MINE;
    }

    public function newMeeting(?string $date = null): void
    {
        abort_unless((bool) auth()->user()->can('meetings.create'), 403);

        $this->dispatch('meeting-form-open', id: null, date: $date);
    }

    public function editMeeting(int $id): void
    {
        $meeting = $this->findVisible($id);
        abort_unless($meeting->canBeManagedBy(auth()->user()), 403);

        if ($meeting->isCancelled()) {
            $this->dispatch('notify', message: 'This meeting was cancelled, so it can no longer be edited.', type: 'error');

            return;
        }

        $this->dispatch('meeting-form-open', id: $meeting->id, date: null);
    }

    public function openMeeting(int $id): void
    {
        $this->openMeetingId = $this->findVisible($id)->id;
    }

    public function closeMeeting(): void
    {
        $this->openMeetingId = null;
    }

    public function cancelMeeting(int $id, MeetingService $service): void
    {
        $meeting = $this->findVisible($id);
        abort_unless($meeting->canBeManagedBy(auth()->user()), 403);

        $service->cancel($meeting);
        $this->openMeetingId = null;
        $this->dispatch('notify', message: 'Meeting cancelled. Everyone invited has been told.', type: 'success');
    }

    /** A staff member answers for themselves, in the app instead of from the email link. */
    public function respond(int $id, string $response, MeetingService $service): void
    {
        $meeting = $this->findVisible($id);
        $attendee = MeetingAttendee::query()->where('meeting_id', $meeting->id)->where('user_id', auth()->id())->first();
        abort_if($attendee === null, 403);

        if (! in_array($response, [MeetingAttendee::RESPONSE_ACCEPTED, MeetingAttendee::RESPONSE_DECLINED], true)) {
            return;
        }

        if (! $service->respond($attendee, $response)) {
            $this->dispatch('notify', message: 'This meeting is over or cancelled, so it can no longer be answered.', type: 'error');

            return;
        }

        $this->dispatch('notify', message: $response === MeetingAttendee::RESPONSE_ACCEPTED ? 'You are marked as coming.' : 'You are marked as not coming.', type: 'success');
    }

    /** Re-render after the form saved; also open the saved meeting so the person sees the result. */
    #[On('meeting-saved')]
    public function onSaved(?int $id = null): void
    {
        if ($id !== null) {
            $this->openMeetingId = $id;
        }
    }

    public function render()
    {
        abort_unless((bool) auth()->user()?->can('meetings.view'), 403);

        $tz = $this->tz();
        $monthStart = $this->monthStart();
        $gridStart = $monthStart->clone()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthStart->clone()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $meetings = $this->visibleMeetings()
            ->where('starts_at', '>=', $gridStart->clone()->utc())
            ->where('starts_at', '<=', $gridEnd->clone()->utc())
            ->with('organizer:id,name')
            ->orderBy('starts_at')
            ->get();

        $byDay = $meetings->groupBy(fn (Meeting $m): string => $m->starts_at->clone()->setTimezone($tz)->format('Y-m-d'));
        $tasksByDay = $this->taskDueDates($gridStart, $gridEnd);

        $weeks = [];
        $day = $gridStart->clone();
        while ($day->lte($gridEnd)) {
            $row = [];
            for ($i = 0; $i < 7; $i++) {
                $key = $day->format('Y-m-d');
                $row[] = [
                    'date' => $key,
                    'day' => $day->day,
                    'inMonth' => $day->month === $monthStart->month,
                    'isToday' => $day->isSameDay($this->now()),
                    'meetings' => $byDay->get($key, collect()),
                    'tasks' => $tasksByDay->get($key, collect()),
                ];
                $day->addDay();
            }
            $weeks[] = $row;
        }

        $open = $this->openMeetingId !== null
            ? Meeting::with(['organizer:id,name,email', 'attendees'])->find($this->openMeetingId)
            : null;
        if ($open !== null && ! $open->canBeViewedBy(auth()->user())) {
            $open = null;
        }

        return view('livewire.meetings.calendar', [
            'tz' => $tz,
            'monthLabel' => $monthStart->format('F Y'),
            'weeks' => $weeks,
            'upcoming' => $this->upcoming(),
            'team' => $this->teamNow(),
            'open' => $open,
            'canManageAll' => (bool) auth()->user()->can('meetings.manage_all'),
            'canCreate' => (bool) auth()->user()->can('meetings.create'),
            'openCanManage' => $open?->canBeManagedBy(auth()->user()) ?? false,
            'myAttendee' => $open?->attendees->firstWhere('user_id', auth()->id()),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Meeting> */
    private function visibleMeetings()
    {
        $query = Meeting::query()->scheduled();
        if ($this->scope === self::SCOPE_EVERYONE && auth()->user()->can('meetings.manage_all')) {
            // "Everyone" means everyone's BlastIQ meetings. A colleague's Google calendar mirror is private to them
            // (it holds their personal appointments too), so only the viewer's own copies are included.
            return $query->where(fn ($q) => $q->where('imported', false)->orWhere('organizer_id', (int) auth()->id()));
        }

        return $query->participating((int) auth()->id());
    }

    private function findVisible(int $id): Meeting
    {
        $meeting = Meeting::findOrFail($id);
        abort_unless($meeting->canBeViewedBy(auth()->user()), 403);

        return $meeting;
    }

    /** The viewer's next meetings, from now. */
    private function upcoming(): Collection
    {
        return $this->visibleMeetings()
            ->where('ends_at', '>', now())
            ->with('organizer:id,name')
            ->orderBy('starts_at')
            ->limit(8)
            ->get();
    }

    /**
     * Open tasks assigned to the viewer, by due day — read-only entries so the calendar also tracks deadlines.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    private function taskDueDates(Carbon $from, Carbon $to): Collection
    {
        $user = auth()->user();
        if (! $user->can('tasks.view')) {
            return collect();
        }

        return Task::query()
            ->openAssignedTo((int) $user->id, TaskStatus::completedSlugs())
            ->whereBetween('due_at', [$from->clone()->utc(), $to->clone()->utc()])
            ->with('board:id,name,slug')
            ->orderBy('due_at')
            ->get()
            ->groupBy(fn (Task $t): string => $t->due_at->clone()->setTimezone($this->tz())->format('Y-m-d'));
    }

    /**
     * Active staff with their manual status, and "in a meeting until" derived from meetings in progress.
     * Only the fact and the end time are shown — never the title of someone else's meeting.
     *
     * @return Collection<int, array{id: int, name: string, presence: string, busyUntil: ?Carbon}>
     */
    private function teamNow(): Collection
    {
        $now = now();
        $busyUntil = [];
        Meeting::query()->scheduled()
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->with(['attendees' => fn ($q) => $q->whereNotNull('user_id')->where('response', '!=', MeetingAttendee::RESPONSE_DECLINED)])
            ->get()
            ->each(function (Meeting $m) use (&$busyUntil): void {
                $ids = $m->attendees->pluck('user_id')->push($m->organizer_id)->filter();
                foreach ($ids as $id) {
                    $busyUntil[$id] = isset($busyUntil[$id]) ? $busyUntil[$id]->max($m->ends_at) : $m->ends_at;
                }
            });

        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(40)
            ->get(['id', 'name', 'presence_status'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'presence' => $u->presence_status ?? User::PRESENCE_AVAILABLE,
                'busyUntil' => $busyUntil[$u->id] ?? null,
            ]);
    }

    private function tz(): string
    {
        return (string) config('app.business_timezone');
    }

    private function now(): Carbon
    {
        return Carbon::now($this->tz());
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01', $this->tz())->startOfDay();
    }
}
