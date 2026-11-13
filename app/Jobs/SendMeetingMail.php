<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\MeetingInviteMail;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Services\MailClient\MailErrorExplainer;
use App\Services\Meetings\MeetingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one invitation / update / cancellation and records the outcome on the attendee, so a failed
 * invitation shows up for the organiser instead of vanishing in the queue.
 */
class SendMeetingMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public readonly int $meetingId,
        public readonly string $kind,
        public readonly string $email,
        public readonly ?string $name,
        public readonly ?int $attendeeId,
    ) {}

    public function handle(): void
    {
        $meeting = Meeting::with(['organizer', 'attendees'])->find($this->meetingId);
        if ($meeting === null) {
            return;
        }

        $attendee = $this->attendeeId !== null ? MeetingAttendee::find($this->attendeeId) : null;

        // Queued mail can be overtaken by events: a retry that runs after the meeting was cancelled must not
        // revive it, and an invitation for someone since removed must not go out without answer links.
        if ($this->kind !== MeetingService::KIND_CANCEL && $meeting->isCancelled()) {
            return;
        }
        if ($this->attendeeId !== null && $attendee === null) {
            return;
        }

        Mail::to($this->email)->send(new MeetingInviteMail($meeting, $this->kind, $this->name, $attendee?->response_token, $this->email));

        $attendee?->forceFill(['invited_at' => now(), 'invite_error' => null])->save();
    }

    public function failed(\Throwable $e): void
    {
        if ($this->attendeeId === null) {
            return;
        }

        MeetingAttendee::whereKey($this->attendeeId)->update([
            'invite_error' => mb_substr(MailErrorExplainer::explain($e, null), 0, 300),
        ]);
    }
}
