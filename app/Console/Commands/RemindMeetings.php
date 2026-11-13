<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\MeetingReminderMail;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a reminder 24 hours and 10 minutes before each meeting to the organiser and everyone who has not
 * declined. Each reminder is stamped on the meeting before sending, so a re-run (or an overlapping tick)
 * can never send it twice. Off unless `meetings.reminders_enabled` — turn on once invitation mail is
 * verified on a real mailbox.
 */
class RemindMeetings extends Command
{
    protected $signature = 'meetings:remind';

    protected $description = 'Email 24-hour and 10-minute meeting reminders';

    /**
     * `minutes` is the lead time; `earliest` is the least time that may still remain for the label to be true. The
     * 24h reminder therefore only goes out in the last hour of its day-before window: after a scheduler or mail outage
     * (or when the flag is first switched on) a meeting starting in 2 hours is not told "starts in 24 hours".
     *
     * @var array<string, array{column: string, minutes: int, earliest: int, label: string}>
     */
    private const WINDOWS = [
        '24h' => ['column' => 'reminder_24h_sent_at', 'minutes' => 24 * 60, 'earliest' => 23 * 60, 'label' => '24 hours'],
        '10m' => ['column' => 'reminder_10m_sent_at', 'minutes' => 10, 'earliest' => 0, 'label' => '10 minutes'],
    ];

    public function handle(): int
    {
        if (! config('meetings.reminders_enabled')) {
            return self::SUCCESS;
        }

        foreach (self::WINDOWS as $window) {
            $this->remind($window['column'], $window['minutes'], $window['earliest'], $window['label']);
        }

        return self::SUCCESS;
    }

    private function remind(string $column, int $minutes, int $earliest, string $label): void
    {
        $now = now();

        // Due when the start is inside the window: no further away than the lead time, no nearer than `earliest`.
        $due = Meeting::query()
            ->scheduled()
            ->whereNull('external_provider') // Google already sends its own reminders for those
            ->whereNull($column)
            ->where('starts_at', '>', $now->clone()->addMinutes($earliest))
            ->where('starts_at', '<=', $now->clone()->addMinutes($minutes))
            ->with(['organizer', 'attendees'])
            ->get();

        foreach ($due as $meeting) {
            // Set up or changed with less notice than this reminder's lead time ("24 hours" for a meeting made
            // 2 hours ahead would be false): never send it. Left unstamped on purpose — the check is cheap.
            if ($meeting->updated_at->gt($meeting->starts_at->clone()->subMinutes($minutes))) {
                continue;
            }

            // Claim first (atomic): only the process that flips the column sends. toBase() so the claim does not
            // touch updated_at — that column is the "last changed" time the short-notice check above relies on.
            $claimed = Meeting::whereKey($meeting->id)->whereNull($column)->toBase()->update([$column => $now]);
            if ($claimed === 0) {
                continue;
            }

            foreach ($this->recipients($meeting) as $email => $name) {
                Mail::to($email)->queue(new MeetingReminderMail($meeting, $label, $name));
            }
        }
    }

    /** @return array<string, ?string> email => name */
    private function recipients(Meeting $meeting): array
    {
        $recipients = [];
        if ($meeting->organizer?->email) {
            $recipients[$meeting->organizer->email] = $meeting->organizer->name;
        }
        foreach ($meeting->attendees as $attendee) {
            if ($attendee->response !== MeetingAttendee::RESPONSE_DECLINED) {
                $recipients[$attendee->email] = $attendee->name;
            }
        }

        return $recipients;
    }
}
