<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailAccount;
use App\Services\MailClient\OutboundAttachment;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One message an employee sends from THEIR OWN connected mailbox (plan B5a) —
 * a reply or a fresh compose. The From is always the account's address (you can
 * only send as an identity you've authenticated), and the RFC 5322 threading
 * headers (Message-ID / In-Reply-To / References) are set so replies thread on
 * the recipient's side and our own re-sync can dedupe the sent copy.
 *
 * Transport is the account's own SMTP, wired up by {@see \App\Services\MailClient\SmtpSender}
 * — this mailable only describes the message, not how it leaves the building.
 */
class UserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailAccount $account,
        public readonly OutboundEmail $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->account->email, $this->account->display_name ?: null),
            to: array_map(static fn (string $addr): Address => new Address($addr), $this->email->to),
            cc: array_map(static fn (string $addr): Address => new Address($addr), $this->email->cc),
            bcc: array_map(static fn (string $addr): Address => new Address($addr), $this->email->bcc),
            subject: $this->email->subject,
        );
    }

    public function content(): Content
    {
        // A raw text-only body is wrapped so it still renders as HTML; a real
        // HTML body is passed through. Inbound content is never echoed here —
        // the composer supplies the operator's own text (B5b).
        $html = $this->email->bodyHtml
            ?? '<pre style="font-family:inherit;white-space:pre-wrap">'.e((string) $this->email->bodyText).'</pre>';

        return new Content(htmlString: $html);
    }

    public function headers(): Headers
    {
        $custom = [];

        if ($this->email->inReplyTo !== null) {
            $custom['In-Reply-To'] = $this->bracket($this->email->inReplyTo);
        }

        if ($this->email->references !== null && $this->email->references !== '') {
            $custom['References'] = $this->email->references;
        }

        return new Headers(
            // Symfony wraps this in <...>; store the bracket-stripped id.
            messageId: $this->email->messageId !== null ? trim($this->email->messageId, '<>') : null,
            text: $custom,
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (OutboundAttachment $att): Attachment => Attachment::fromStorageDisk('local', $att->diskPath)
                ->as($att->filename)
                ->withMime($att->mime),
            $this->email->attachments,
        );
    }

    private function bracket(string $id): string
    {
        $id = trim($id, '<>');

        return "<{$id}>";
    }
}
