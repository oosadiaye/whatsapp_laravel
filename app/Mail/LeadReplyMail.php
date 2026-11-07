<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the lead's owner that a prospect answered, or that an AI decision needs them. */
class LeadReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $headline,
        public readonly string $snippet,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.lead-reply', with: [
            'lead' => $this->lead,
            'headline' => $this->headline,
            'snippet' => $this->snippet,
            'url' => $this->url,
        ]);
    }
}
