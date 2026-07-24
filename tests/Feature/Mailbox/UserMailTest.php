<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Mail\UserMail;
use App\Models\EmailAccount;
use App\Services\MailClient\OutboundAttachment;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan B5a — the outbound mailable. Verifies the employee sends AS their own
 * account identity, with the RFC 5322 threading headers a reply needs and
 * attachments pulled from the private disk.
 */
class UserMailTest extends TestCase
{
    use RefreshDatabase;

    private function account(): EmailAccount
    {
        return EmailAccount::factory()->create([
            'email' => 'agent@company.test',
            'display_name' => 'Agent Smith',
        ]);
    }

    public function test_sends_from_the_account_identity_to_the_recipients(): void
    {
        $email = new OutboundEmail(
            to: ['client@example.com'],
            subject: 'Re: Quote',
            bodyHtml: '<p>Here you go</p>',
            cc: ['manager@example.com'],
        );

        $mailable = new UserMail($this->account(), $email);

        $mailable->assertFrom('agent@company.test', 'Agent Smith');
        $mailable->assertHasTo('client@example.com');
        $mailable->assertHasCc('manager@example.com');
        $mailable->assertHasSubject('Re: Quote');
        $mailable->assertSeeInHtml('Here you go', false);
    }

    public function test_sets_the_threading_headers_for_a_reply(): void
    {
        $email = (new OutboundEmail(
            to: ['client@example.com'],
            subject: 'Re: Quote',
            bodyText: 'thanks',
            inReplyTo: 'parent@company.test',
            references: '<root@company.test> <parent@company.test>',
        ))->withMessageId('<new-123@company.test>');

        $mailable = new UserMail($this->account(), $email);
        $mailable->render();
        $headers = $mailable->headers();

        // Symfony re-wraps the Message-ID, so we store it bracket-stripped.
        $this->assertSame('new-123@company.test', $headers->messageId);
        // In-Reply-To is normalised to a single bracketed id even when passed bare.
        $this->assertSame('<parent@company.test>', $headers->text['In-Reply-To'] ?? null);
        $this->assertSame('<root@company.test> <parent@company.test>', $headers->text['References'] ?? null);
    }

    public function test_a_text_only_body_still_renders_as_html(): void
    {
        $email = new OutboundEmail(to: ['c@example.com'], subject: 'Plain', bodyText: 'line one');

        $mailable = new UserMail($this->account(), $email);

        $mailable->assertSeeInHtml('line one', false);
    }

    public function test_attaches_files_from_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('mailbox/outbox/report.pdf', 'PDFDATA');

        $email = new OutboundEmail(
            to: ['c@example.com'],
            subject: 'With file',
            bodyHtml: '<p>see attached</p>',
            attachments: [new OutboundAttachment('mailbox/outbox/report.pdf', 'report.pdf', 'application/pdf')],
        );

        $mailable = new UserMail($this->account(), $email);

        // attachments() objects hydrate through the data path (bytes read from
        // the disk), so match with an equivalent Attachment rather than the
        // storage-disk registry helper.
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'mailbox/outbox/report.pdf')
                ->as('report.pdf')
                ->withMime('application/pdf'),
        );
    }
}
