<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the organiser someone declined, so they are not waiting for a person who will not come. */
class MeetingResponseMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $personName,
        public readonly string $response,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->personName.' declined: '.$this->meeting->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.meeting-response',
            with: [
                'meeting' => $this->meeting,
                'personName' => $this->personName,
                'when' => $this->meeting->starts_at->clone()->setTimezone((string) config('app.business_timezone'))->format('l j F Y, H:i'),
                'meetingsUrl' => route('meetings.index'),
            ],
        );
    }
}
