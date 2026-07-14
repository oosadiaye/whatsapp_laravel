<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * One campaign email to one recipient. Provider-agnostic (Laravel Mail), so the
 * transport is whatever `MAIL_MAILER` points at.
 *
 * Personalises `{{name}}` / `{{email}}` placeholders, appends a mandatory
 * unsubscribe footer, and sets the `List-Unsubscribe` headers (RFC 2369/8058)
 * — both required for legitimate bulk email and better deliverability.
 */
class CampaignEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailCampaign $campaign,
        public readonly string $recipientEmail,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        $fromName = $this->campaign->from_name ?: config('mail.from.name');

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $fromName),
            replyTo: $this->campaign->reply_to ? [new Address($this->campaign->reply_to)] : [],
            subject: $this->personalize($this->campaign->subject),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderHtml());
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function personalize(string $text): string
    {
        return strtr($text, [
            '{{name}}' => $this->recipientName ?? '',
            '{{ name }}' => $this->recipientName ?? '',
            '{{email}}' => $this->recipientEmail,
            '{{ email }}' => $this->recipientEmail,
        ]);
    }

    private function renderHtml(): string
    {
        $body = $this->personalize($this->campaign->body_html);
        $sender = e((string) ($this->campaign->from_name ?: config('mail.from.name')));

        $footer = '<hr style="margin-top:32px;border:none;border-top:1px solid #e5e7eb">'
            .'<p style="font-size:12px;color:#9ca3af;line-height:1.5;margin-top:12px">'
            .'You received this email because you are a contact of '.$sender.'. '
            .'<a href="'.e($this->unsubscribeUrl()).'" style="color:#6b7280">Unsubscribe</a>.'
            .'</p>';

        return $body.$footer;
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe', ['email' => $this->recipientEmail]);
    }
}
