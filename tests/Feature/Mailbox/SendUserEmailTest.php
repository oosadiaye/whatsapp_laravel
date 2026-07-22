<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Jobs\SendUserEmail;
use App\Mail\UserMail;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\OutboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Plan B5a — the send job. Verifies it dispatches through the account's own SMTP
 * (via {@see UserMail}), stores the sent copy in the right thread, refuses to
 * graft onto another account's thread, skips inactive accounts, and is NOT
 * retried (a send has no wire idempotency key — a retry would double-deliver).
 */
class SendUserEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail_client.enabled' => true]);
    }

    private function account(array $overrides = []): EmailAccount
    {
        return EmailAccount::factory()->create(array_merge([
            'email' => 'agent@company.test',
            'provider' => EmailAccount::PROVIDER_IMAP,
            'is_active' => true,
            'credentials' => [
                'smtp_host' => 'smtp.company.test',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'username' => 'agent@company.test',
                'password' => 'secret',
            ],
        ], $overrides));
    }

    private function dispatchJob(EmailAccount $account, OutboundEmail $email): void
    {
        (new SendUserEmail($account->id, $email))->handle(app(MailAccountProviderFactory::class));
    }

    public function test_sends_via_smtp_and_stores_the_sent_copy_in_the_reply_thread(): void
    {
        Mail::fake();
        $account = $this->account();
        $thread = EmailThread::factory()->create([
            'email_account_id' => $account->id,
            'subject' => 'Original',
        ]);

        $this->dispatchJob($account, new OutboundEmail(
            to: ['client@example.com'],
            subject: 'Re: Original',
            bodyHtml: '<p>reply</p>',
            inReplyTo: '<parent@company.test>',
            references: '<parent@company.test>',
            threadId: $thread->id,
        ));

        Mail::assertSent(UserMail::class, fn (UserMail $m): bool => $m->account->is($account) && $m->hasTo('client@example.com'));

        $stored = EmailMessage::query()
            ->where('email_thread_id', $thread->id)
            ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('agent@company.test', $stored->from_email);
        $this->assertSame('<parent@company.test>', $stored->in_reply_to);
        $this->assertNotNull($stored->message_id);
        $this->assertStringNotContainsString('<', (string) $stored->message_id); // stored bracket-stripped
    }

    public function test_a_fresh_compose_opens_a_new_sent_thread(): void
    {
        Mail::fake();
        $account = $this->account();

        $this->dispatchJob($account, new OutboundEmail(
            to: ['new@example.com'],
            subject: 'Hello there',
            bodyText: 'hi',
        ));

        $thread = EmailThread::where('email_account_id', $account->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame(EmailThread::FOLDER_SENT, $thread->folder);
        $this->assertSame('Hello there', $thread->subject);
        $this->assertDatabaseHas('email_messages', [
            'email_thread_id' => $thread->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
        ]);
    }

    public function test_a_thread_from_another_account_is_never_reused(): void
    {
        Mail::fake();
        $mine = $this->account(['email' => 'me@company.test']);
        $other = $this->account(['email' => 'other@company.test']);
        $foreign = EmailThread::factory()->create(['email_account_id' => $other->id, 'subject' => 'Foreign']);

        $this->dispatchJob($mine, new OutboundEmail(
            to: ['x@example.com'],
            subject: 'Sneaky',
            threadId: $foreign->id, // belongs to $other — must not be grafted onto
        ));

        $this->assertSame(0, $foreign->messages()->count());

        $newThread = EmailThread::where('email_account_id', $mine->id)->first();
        $this->assertNotNull($newThread);
        $this->assertDatabaseHas('email_messages', [
            'email_thread_id' => $newThread->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
        ]);
    }

    public function test_an_inactive_account_neither_sends_nor_stores(): void
    {
        Mail::fake();
        $account = $this->account(['is_active' => false]);

        $this->dispatchJob($account, new OutboundEmail(to: ['x@example.com'], subject: 'Nope'));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_messages', 0);
    }

    public function test_the_send_job_is_not_retried(): void
    {
        // A send has no idempotency key — a retry would double-deliver.
        $job = new SendUserEmail(1, new OutboundEmail(to: ['x@example.com'], subject: 'x'));

        $this->assertSame(1, $job->tries);
    }
}
