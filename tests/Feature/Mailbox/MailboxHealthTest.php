<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Exceptions\MailAuthException;
use App\Jobs\SendUserEmail;
use App\Jobs\SyncEmailAccount;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\FetchResult;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\MailFetcher;
use App\Services\MailClient\OutboundEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Mail that does not arrive, or does not leave, is never silent: the sync records why it is stuck,
 * each mailbox is checked on its own interval, and a message that cannot be sent is kept and marked.
 */
final class MailboxHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
    }

    private function factoryFetching(\Closure $onFetch): MailAccountProviderFactory
    {
        return new class($onFetch) extends MailAccountProviderFactory
        {
            public function __construct(private readonly \Closure $onFetch) {}

            public function fetcherFor(EmailAccount $account): ?MailFetcher
            {
                $onFetch = $this->onFetch;

                return new class($onFetch) implements MailFetcher
                {
                    public function __construct(private readonly \Closure $onFetch) {}

                    public function fetch(EmailAccount $account): FetchResult
                    {
                        return ($this->onFetch)($account);
                    }
                };
            }
        };
    }

    // ---- sync status -------------------------------------------------------------------------

    public function test_a_failed_check_records_a_plain_reason_and_a_good_one_clears_it(): void
    {
        $account = EmailAccount::factory()->create(['is_active' => true]);

        try {
            (new SyncEmailAccount($account->id))->handle(
                new EmailSyncService,
                $this->factoryFetching(fn () => throw new RuntimeException('Connection could not be established with host "imap.x.test:993"')),
            );
            $this->fail('A transient failure must still propagate so the queue retries.');
        } catch (RuntimeException) {
            // expected
        }

        $account->refresh();
        $this->assertNotNull($account->last_sync_attempt_at);
        $this->assertStringContainsString('Could not reach the mail server', (string) $account->last_sync_error);

        (new SyncEmailAccount($account->id))->handle(
            new EmailSyncService,
            $this->factoryFetching(fn () => new FetchResult([], ['inbox' => ['uidvalidity' => 1, 'last_uid' => 0, 'backfill_below' => 0]])),
        );

        $this->assertNull($account->fresh()->last_sync_error);
    }

    public function test_a_rejected_login_flags_the_mailbox_and_names_app_passwords_for_yahoo(): void
    {
        $account = EmailAccount::factory()->create([
            'is_active' => true,
            'credentials' => ['imap_host' => 'imap.mail.yahoo.com', 'username' => 'a@yahoo.com', 'password' => 'x'],
        ]);

        (new SyncEmailAccount($account->id))->handle(
            new EmailSyncService,
            $this->factoryFetching(fn () => throw new MailAuthException('AUTHENTICATIONFAILED Invalid credentials')),
        );

        $account->refresh();
        $this->assertTrue($account->needs_reauth);
        $this->assertStringContainsStringIgnoringCase('app password', (string) $account->last_sync_error);
    }

    // ---- per-mailbox interval ----------------------------------------------------------------

    public function test_only_mailboxes_that_are_due_are_queued(): void
    {
        Queue::fake();
        $neverChecked = EmailAccount::factory()->create(['sync_interval_minutes' => 5, 'last_sync_attempt_at' => null]);
        $recent = EmailAccount::factory()->create(['sync_interval_minutes' => 5, 'last_sync_attempt_at' => now()->subMinutes(1)]);
        $overdue = EmailAccount::factory()->create(['sync_interval_minutes' => 5, 'last_sync_attempt_at' => now()->subMinutes(6)]);
        $needsLogin = EmailAccount::factory()->create(['needs_reauth' => true, 'last_sync_attempt_at' => null]);

        Artisan::call('mailbox:sync');

        $queued = collect(Queue::pushed(SyncEmailAccount::class))->map(fn ($job) => $job->accountId)->all();
        $this->assertEqualsCanonicalizing([$neverChecked->id, $overdue->id], $queued);
        $this->assertNotContains($recent->id, $queued);
        $this->assertNotContains($needsLogin->id, $queued);
    }

    public function test_all_forces_every_healthy_mailbox(): void
    {
        Queue::fake();
        $recent = EmailAccount::factory()->create(['sync_interval_minutes' => 30, 'last_sync_attempt_at' => now()]);

        Artisan::call('mailbox:sync', ['--all' => true]);

        Queue::assertPushed(SyncEmailAccount::class, fn (SyncEmailAccount $job): bool => $job->accountId === $recent->id);
    }

    public function test_an_owner_picks_how_often_their_mailbox_is_checked(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('agent');
        $account = EmailAccount::factory()->for($user)->create();

        $this->actingAs($user)->put(route('mailbox.accounts.sync-interval', $account), ['sync_interval_minutes' => 10])
            ->assertRedirect(route('mailbox.accounts.edit', $account));
        $this->assertSame(10, $account->fresh()->sync_interval_minutes);

        $this->actingAs($user)->put(route('mailbox.accounts.sync-interval', $account), ['sync_interval_minutes' => 7])
            ->assertSessionHasErrors('sync_interval_minutes');
        $this->assertSame(10, $account->fresh()->sync_interval_minutes);
    }

    public function test_nobody_can_change_another_persons_interval(): void
    {
        $owner = EmailAccount::factory()->create();
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('agent');

        $this->actingAs($other)->put(route('mailbox.accounts.sync-interval', $owner), ['sync_interval_minutes' => 5])->assertForbidden();
    }

    // ---- sending failures --------------------------------------------------------------------

    public function test_a_message_that_cannot_be_sent_is_kept_in_sent_marked_with_the_reason(): void
    {
        $account = EmailAccount::factory()->create([
            'credentials' => ['smtp_host' => 'smtp.mail.yahoo.com', 'username' => 'a@yahoo.com', 'password' => 'x'],
        ]);

        (new SendUserEmail($account->id, new OutboundEmail(to: ['bob@example.com'], subject: 'Quote', bodyText: 'Hello Bob')))
            ->failed(new RuntimeException('Expected response code "250" but got code "535", with message "535 Authentication failed"'));

        $message = EmailMessage::query()->where('email_account_id', $account->id)->sole();
        $this->assertTrue($message->isSendFailure());
        $this->assertSame(['bob@example.com'], $message->to);
        $this->assertStringContainsString('username or password', $message->send_error);
        $this->assertStringContainsStringIgnoringCase('app password', $message->send_error);
        $this->assertSame(EmailThread::FOLDER_SENT, $message->thread->folder);
        $this->assertSame(1, $message->thread->unread_count);
        $this->assertNull($message->sent_at);
    }

    public function test_a_failed_reply_stays_in_its_own_conversation(): void
    {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);

        (new SendUserEmail($account->id, new OutboundEmail(to: ['bob@example.com'], subject: 'Re: Quote', bodyText: 'ok', threadId: $thread->id)))
            ->failed(new RuntimeException('550 5.1.1 user unknown'));

        $this->assertSame(1, EmailThread::query()->count());
        $this->assertSame($thread->id, EmailMessage::query()->sole()->email_thread_id);
    }

    public function test_a_send_that_works_makes_exactly_one_conversation(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $account = EmailAccount::factory()->create([
            'credentials' => ['smtp_host' => 'smtp.x.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'username' => 'u', 'password' => 'p'],
        ]);

        (new SendUserEmail($account->id, new OutboundEmail(to: ['bob@example.com'], subject: 'Hi', bodyText: 'Hello')))
            ->handle(app(MailAccountProviderFactory::class));

        $this->assertSame(1, EmailThread::query()->count());
        $message = EmailMessage::query()->sole();
        $this->assertFalse($message->isSendFailure());
        $this->assertNotNull($message->sent_at);
    }

    public function test_a_disconnected_mailbox_tells_the_person_instead_of_dropping_the_message(): void
    {
        $account = EmailAccount::factory()->create(['is_active' => false]);
        $job = new SendUserEmail($account->id, new OutboundEmail(to: ['bob@example.com'], subject: 'Hi', bodyText: 'Hello'));

        $job->failed(new \App\Exceptions\MailSendException('This mailbox is disconnected, so the message was not sent.'));

        $this->assertStringContainsString('disconnected', EmailMessage::query()->sole()->send_error);
    }

    // ---- bounces -----------------------------------------------------------------------------

    public function test_delivery_failure_notices_are_recognised(): void
    {
        $bounce = EmailMessage::factory()->make(['from_email' => 'MAILER-DAEMON@yahoo.com', 'subject' => 'Delivery Status Notification (Failure)']);
        $subjectOnly = EmailMessage::factory()->make(['from_email' => 'x@y.com', 'subject' => 'Undeliverable: Quote']);
        $normal = EmailMessage::factory()->make(['from_email' => 'bob@example.com', 'subject' => 'Re: Quote']);
        $outbound = EmailMessage::factory()->make(['direction' => EmailMessage::DIRECTION_OUTBOUND, 'from_email' => 'mailer-daemon@x.com']);

        $this->assertTrue($bounce->isBounce());
        $this->assertTrue($subjectOnly->isBounce());
        $this->assertFalse($normal->isBounce());
        $this->assertFalse($outbound->isBounce());
    }
}
