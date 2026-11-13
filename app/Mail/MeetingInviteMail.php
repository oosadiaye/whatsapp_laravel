<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Meeting;
use App\Services\Meetings\IcsBuilder;
use App\Services\Meetings\MeetingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation, an update of it, or its cancellation — with the .ics calendar file attached so it
 * lands in Google / Outlook / Yahoo / Apple calendars. Sent by {@see \App\Jobs\SendMeetingMail}.
 */
class MeetingInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $kind,
        public readonly ?string $recipientName = null,
        public readonly ?string $responseToken = null,
        public readonly ?string $recipientEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->kind) {
            MeetingService::KIND_UPDATE => 'Updated: ',
            MeetingService::KIND_CANCEL => 'Cancelled: ',
            default => 'Invitation: ',
        };

        return new Envelope(subject: $prefix.$this->meeting->title);
    }

    public function content(): Content
    {
        $tz = (string) config('app.business_timezone');
        $token = $this->kind === MeetingService::KIND_CANCEL ? null : $this->responseToken;

        return new Content(
            view: 'mail.meeting-invite',
            with: [
                'meeting' => $this->meeting,
                'kind' => $this->kind,
                'recipientName' => $this->recipientName,
                'when' => $this->meeting->starts_at->clone()->setTimezone($tz)->format('l j F Y, H:i')
                    .' – '.$this->meeting->ends_at->clone()->setTimezone($tz)->format('H:i').' ('.$tz.')',
                'acceptUrl' => $token ? route('meetings.respond.show', ['token' => $token, 'answer' => 'accepted']) : null,
                'declineUrl' => $token ? route('meetings.respond.show', ['token' => $token, 'answer' => 'declined']) : null,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $method = $this->kind === MeetingService::KIND_CANCEL ? IcsBuilder::METHOD_CANCEL : IcsBuilder::METHOD_REQUEST;
        // Only this recipient in the file: a calendar entry must not hand every client the others' addresses.
        $ics = IcsBuilder::build($this->meeting, $method, $this->recipientEmail ?? '');

        return [
            Attachment::fromData(fn () => $ics, 'meeting.ics')->withMime('text/calendar; method='.$method.'; charset=UTF-8'),
        ];
    }
}
