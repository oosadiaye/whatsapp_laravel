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

/** "Starts in 24 hours" / "Starts in 10 minutes" — sent by `meetings:remind`. */
class MeetingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $leadLabel,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reminder ('.$this->leadLabel.'): '.$this->meeting->title);
    }

    public function content(): Content
    {
        $tz = (string) config('app.business_timezone');

        return new Content(
            view: 'mail.meeting-reminder',
            with: [
                'meeting' => $this->meeting,
                'leadLabel' => $this->leadLabel,
                'recipientName' => $this->recipientName,
                'when' => $this->meeting->starts_at->clone()->setTimezone($tz)->format('l j F Y, H:i').' ('.$tz.')',
            ],
        );
    }
}
