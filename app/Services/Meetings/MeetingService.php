<?php

declare(strict_types=1);

namespace App\Services\Meetings;

use App\Jobs\SendMeetingMail;
use App\Mail\MeetingResponseMail;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Services\Google\GoogleCalendarSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creating, changing, cancelling and answering meetings, plus the invitation mail that goes with each.
 *
 * Attendees arrive as plain rows: ['email' => ..., 'name' => ?, 'user_id' => ?, 'contact_id' => ?].
 * The organiser is implicit and never stored as an attendee.
 */
final class MeetingService
{
    public const KIND_INVITE = 'invite';

    public const KIND_UPDATE = 'update';

    public const KIND_CANCEL = 'cancel';

    /** Set when something went wrong with Google and BlastIQ emailed people itself; the caller may show it. */
    public ?string $warning = null;

    public function __construct(private readonly GoogleCalendarSync $google) {}

    /**
     * @param  array{title: string, description?: ?string, location?: ?string, video_url?: ?string, starts_at: Carbon, ends_at: Carbon}  $data
     * @param  list<array{email: string, name?: ?string, user_id?: ?int, contact_id?: ?int}>  $attendees
     */
    public function create(User $organizer, array $data, array $attendees, bool $sendInvites = true): Meeting
    {
        $this->warning = null;
        $rows = $this->normalise($attendees, $organizer->email);

        $meeting = DB::transaction(function () use ($organizer, $data, $rows): Meeting {
            $meeting = Meeting::create([
                'organizer_id' => $organizer->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'video_url' => $data['video_url'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'status' => Meeting::STATUS_SCHEDULED,
                'uid' => (string) Str::uuid(),
                'sequence' => 0,
            ]);

            foreach ($rows as $row) {
                $meeting->attendees()->create($row + ['response' => MeetingAttendee::RESPONSE_PENDING, 'response_token' => Str::random(40)]);
            }

            return $meeting;
        });

        if ($sendInvites) {
            $this->sendInvites($meeting);
        }

        return $meeting;
    }

    /** Email every invitee. Used on create, and as the fallback when Google could not be told about a meeting. */
    public function sendInvites(Meeting $meeting): void
    {
        foreach ($meeting->attendees()->get() as $attendee) {
            $this->dispatchMail($meeting, self::KIND_INVITE, $attendee->email, $attendee->name, $attendee);
        }
    }

    /**
     * @param  array{title: string, description?: ?string, location?: ?string, video_url?: ?string, starts_at: Carbon, ends_at: Carbon}  $data
     * @param  list<array{email: string, name?: ?string, user_id?: ?int, contact_id?: ?int}>  $attendees
     */
    public function update(Meeting $meeting, array $data, array $attendees): Meeting
    {
        $this->warning = null;

        if ($meeting->isCancelled()) {
            // Updating would mail everyone a "confirmed" calendar entry for a meeting that stays cancelled.
            throw new InvalidArgumentException('This meeting was cancelled and can no longer be edited.');
        }

        $rows = $this->normalise($attendees, $meeting->organizer?->email ?? '');
        $timeChanged = ! $meeting->starts_at->equalTo($data['starts_at']) || ! $meeting->ends_at->equalTo($data['ends_at']);

        $removed = [];
        $added = [];
        $kept = [];

        DB::transaction(function () use ($meeting, $data, $rows, $timeChanged, &$removed, &$added, &$kept): void {
            $meeting->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'video_url' => $data['video_url'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'sequence' => $meeting->sequence + 1,
            ]);
            if ($timeChanged) {
                // A new time is a new question: earlier answers no longer apply, and the reminders must fire again.
                $meeting->reminder_24h_sent_at = null;
                $meeting->reminder_10m_sent_at = null;
            }
            $meeting->save();

            $existing = $meeting->attendees()->get()->keyBy('email');
            $wanted = collect($rows)->keyBy('email');

            foreach ($existing->diffKeys($wanted) as $gone) {
                $removed[] = ['email' => $gone->email, 'name' => $gone->name];
                $gone->delete();
            }
            foreach ($wanted->diffKeys($existing) as $row) {
                $added[] = $meeting->attendees()->create($row + ['response' => MeetingAttendee::RESPONSE_PENDING, 'response_token' => Str::random(40)]);
            }
            foreach ($existing->intersectByKeys($wanted) as $attendee) {
                if ($timeChanged) {
                    $attendee->forceFill(['response' => MeetingAttendee::RESPONSE_PENDING, 'responded_at' => null])->save();
                }
                $kept[] = $attendee;
            }
        });

        $meeting->unsetRelation('attendees');

        // A meeting that lives in Google Calendar is changed there: Google tells the guests. Only if that fails do we
        // email them ourselves, so nobody is left holding the old time.
        if ($meeting->isGoogleManaged()) {
            $error = $this->google->update($meeting->fresh(['attendees']));
            if ($error === null) {
                return $meeting;
            }
            $this->warning = 'Saved here, but Google Calendar could not be updated: '.$error.' The guests were emailed by BlastIQ instead.';
        }

        foreach ($removed as $gone) {
            $this->dispatchMail($meeting, self::KIND_CANCEL, $gone['email'], $gone['name'], null);
        }
        foreach ($added as $attendee) {
            $this->dispatchMail($meeting, self::KIND_INVITE, $attendee->email, $attendee->name, $attendee);
        }
        foreach ($kept as $attendee) {
            $this->dispatchMail($meeting, self::KIND_UPDATE, $attendee->email, $attendee->name, $attendee);
        }

        return $meeting;
    }

    public function cancel(Meeting $meeting): void
    {
        $this->warning = null;
        if ($meeting->isCancelled()) {
            return;
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'sequence' => $meeting->sequence + 1,
        ])->save();

        if ($meeting->isGoogleManaged()) {
            $error = $this->google->cancel($meeting);
            if ($error === null) {
                return; // Google removed the event and told the guests
            }
            $this->warning = 'Cancelled here, but Google Calendar could not be updated: '.$error.' The guests were emailed by BlastIQ instead.';
        }

        foreach ($meeting->attendees as $attendee) {
            $this->dispatchMail($meeting, self::KIND_CANCEL, $attendee->email, $attendee->name, $attendee);
        }
    }

    /**
     * Record an attendee's answer. Returns false when it cannot be recorded (meeting cancelled or already over).
     * A decline tells the organiser, so they are not left waiting for someone who will not come.
     */
    public function respond(MeetingAttendee $attendee, string $response): bool
    {
        if (! in_array($response, [MeetingAttendee::RESPONSE_ACCEPTED, MeetingAttendee::RESPONSE_DECLINED], true)) {
            throw new InvalidArgumentException('Unknown response.');
        }

        $meeting = $attendee->meeting;
        if ($meeting->isCancelled() || $meeting->ends_at->isPast()) {
            return false;
        }

        $changed = $attendee->response !== $response;
        $attendee->forceFill(['response' => $response, 'responded_at' => now()])->save();

        if ($changed && $response === MeetingAttendee::RESPONSE_DECLINED && $meeting->organizer?->email) {
            Mail::to($meeting->organizer->email)->queue(new MeetingResponseMail($meeting, $attendee->displayName(), $response));
        }

        return true;
    }

    /**
     * For each staff user who already has an overlapping meeting, the first clashing one. Warn-only: the
     * caller shows it, nothing here blocks scheduling.
     *
     * @param  list<int>  $userIds
     * @return array<int, Meeting>
     */
    public function conflicts(array $userIds, Carbon $start, Carbon $end, ?int $ignoreMeetingId = null): array
    {
        $clashes = [];
        foreach (array_unique($userIds) as $userId) {
            $clash = Meeting::query()
                ->scheduled()
                ->overlapping($start, $end)
                ->involving($userId)
                ->when($ignoreMeetingId, fn ($q) => $q->whereKeyNot($ignoreMeetingId))
                ->orderBy('starts_at')
                ->first();
            if ($clash !== null) {
                $clashes[$userId] = $clash;
            }
        }

        return $clashes;
    }

    /**
     * Valid, lower-cased, de-duplicated, organiser removed, capped.
     *
     * @param  list<array<string, mixed>>  $attendees
     * @return list<array{email: string, name: ?string, user_id: ?int, contact_id: ?int}>
     */
    private function normalise(array $attendees, string $organizerEmail): array
    {
        $byEmail = [];
        foreach ($attendees as $row) {
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $email === Str::lower($organizerEmail)) {
                continue;
            }
            $byEmail[$email] ??= [
                'email' => $email,
                'name' => filled($row['name'] ?? null) ? Str::limit(trim((string) $row['name']), 120, '') : null,
                'user_id' => $row['user_id'] ?? null,
                'contact_id' => $row['contact_id'] ?? null,
            ];
        }

        if (count($byEmail) > Meeting::MAX_ATTENDEES) {
            throw new InvalidArgumentException('A meeting can have at most '.Meeting::MAX_ATTENDEES.' invited people.');
        }

        return array_values($byEmail);
    }

    private function dispatchMail(Meeting $meeting, string $kind, string $email, ?string $name, ?MeetingAttendee $attendee): void
    {
        SendMeetingMail::dispatch($meeting->id, $kind, $email, $name, $attendee?->id);
    }
}
