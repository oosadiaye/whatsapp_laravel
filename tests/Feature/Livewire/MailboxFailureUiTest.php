<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\SendUserEmail;
use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\MailClient\FetchResult;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\MailFetcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** What the person sees when mail cannot be sent, cannot be fetched, or was bounced. */
final class MailboxFailureUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('agent');
        $this->account = EmailAccount::factory()->for($this->user)->create(['is_active' => true, 'signature' => null]);
    }

    private function failedSend(?EmailAccount $account = null, array $overrides = []): EmailMessage
    {
        $account ??= $this->account;
        $thread = EmailThread::factory()->create([
            'email_account_id' => $account->id, 'folder' => EmailThread::FOLDER_SENT, 'subject' => 'Quote for Bob',
        ]);

        return EmailMessage::factory()->create(array_merge([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'from_email' => $account->email,
            'to' => ['bob@example.com'],
            'cc' => ['cc@example.com'],
            'subject' => 'Quote for Bob',
            'body_text' => 'Hello Bob',
            'send_status' => EmailMessage::SEND_FAILED,
            'send_error' => 'The recipient address was refused.',
            'received_at' => null,
            'sent_at' => null,
        ], $overrides));
    }

    private function inbox(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->user)->test(Inbox::class);
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

    // ---- not sent ----------------------------------------------------------------------------

    public function test_a_banner_and_a_badge_tell_the_person_a_message_did_not_go(): void
    {
        $this->failedSend();

        $this->inbox()->set('folder', 'sent')
            ->assertSee('1 message could not be sent.')
            ->assertSee('Not sent');
    }

    public function test_other_peoples_failed_sends_are_not_counted(): void
    {
        $this->failedSend(EmailAccount::factory()->create());

        $this->inbox()->assertDontSee('could not be sent');
    }

    public function test_the_reason_and_the_next_steps_show_on_the_failed_message(): void
    {
        $failed = $this->failedSend();

        $this->inbox()->set('folder', 'sent')->call('selectThread', $failed->email_thread_id)
            ->assertSee('This message was not sent.')
            ->assertSee('The recipient address was refused.')
            ->assertSee('Edit and send again');
    }

    public function test_see_why_opens_the_failed_conversation_in_sent(): void
    {
        $failed = $this->failedSend();

        $this->inbox()->call('showFailedSends')
            ->assertSet('folder', EmailThread::FOLDER_SENT)
            ->assertSet('selectedThreadId', $failed->email_thread_id);
    }

    public function test_a_fresh_failure_raises_one_toast_and_not_a_second(): void
    {
        $c = $this->inbox();
        $this->failedSend();

        $c->call('refreshMailbox')->assertDispatched('notify');
        $c->call('refreshMailbox')->assertNotDispatched('notify');
    }

    public function test_edit_and_send_again_refills_the_composer_and_replaces_the_failed_copy(): void
    {
        Queue::fake();
        $failed = $this->failedSend();

        $this->inbox()
            ->call('editFailed', $failed->id)
            ->assertSet('composing', true)
            ->assertSet('composeTo', 'bob@example.com')
            ->assertSet('composeCc', 'cc@example.com')
            ->assertSet('composeSubject', 'Quote for Bob')
            ->assertSet('composeBody', 'Hello Bob')
            ->set('composeTo', 'bob@example.org')
            ->call('send')
            ->assertHasNoErrors();

        Queue::assertPushed(SendUserEmail::class, fn (SendUserEmail $job): bool => $job->email->to === ['bob@example.org']);
        $this->assertDatabaseMissing('email_messages', ['id' => $failed->id]);
        $this->assertDatabaseMissing('email_threads', ['id' => $failed->email_thread_id]);
    }

    public function test_nobody_can_edit_or_discard_someone_elses_failed_message(): void
    {
        $theirs = $this->failedSend(EmailAccount::factory()->create());

        $this->inbox()->call('editFailed', $theirs->id)->assertStatus(404);
        $this->inbox()->call('discardFailed', $theirs->id)->assertStatus(404);
        $this->assertDatabaseHas('email_messages', ['id' => $theirs->id]);
    }

    public function test_discarding_removes_the_message_and_its_empty_conversation(): void
    {
        $failed = $this->failedSend();

        $this->inbox()->set('selectedThreadId', $failed->email_thread_id)->call('discardFailed', $failed->id)
            ->assertSet('selectedThreadId', null);

        $this->assertDatabaseMissing('email_messages', ['id' => $failed->id]);
        $this->assertDatabaseMissing('email_threads', ['id' => $failed->email_thread_id]);
    }

    // ---- addresses ---------------------------------------------------------------------------

    public function test_a_mistyped_address_is_named_and_nothing_is_sent(): void
    {
        Queue::fake();

        $this->inbox()->call('startCompose')
            ->set('composeTo', 'bob@yahoo.com, not-an-email')
            ->set('composeSubject', 'Hi')
            ->set('composeBody', 'Hello')
            ->call('send')
            ->assertHasErrors('composeTo')
            ->assertSee('not-an-email')
            ->assertSet('composing', true);

        Queue::assertNothingPushed();
    }

    public function test_a_bad_cc_is_reported_too(): void
    {
        Queue::fake();

        $this->inbox()->call('startCompose')
            ->set('composeTo', 'bob@yahoo.com')->set('composeCc', 'oops')
            ->set('composeSubject', 'Hi')->set('composeBody', 'Hello')
            ->call('send')
            ->assertHasErrors('composeCc');

        Queue::assertNothingPushed();
    }

    public function test_names_in_angle_brackets_and_other_providers_are_accepted(): void
    {
        Queue::fake();

        $this->inbox()->call('startCompose')
            ->set('composeTo', 'Bob Smith <bob@yahoo.com>; ada@outlook.com')
            ->set('composeSubject', 'Hi')->set('composeBody', 'Hello')
            ->call('send')
            ->assertHasNoErrors();

        Queue::assertPushed(SendUserEmail::class, fn (SendUserEmail $job): bool => $job->email->to === ['bob@yahoo.com', 'ada@outlook.com']);
    }

    // ---- receiving ---------------------------------------------------------------------------

    public function test_a_mailbox_that_is_not_updating_says_why_at_the_top_of_the_inbox(): void
    {
        $this->account->update(['last_sync_error' => 'Could not reach the mail server.']);

        $this->inbox()->assertSee('is not coming in.')->assertSee('Could not reach the mail server.');
    }

    public function test_check_now_reports_the_real_reason_when_it_fails(): void
    {
        $this->app->instance(MailAccountProviderFactory::class, $this->factoryFetching(
            fn () => throw new RuntimeException('Connection could not be established with host "imap.x.test:993"')
        ));

        $this->inbox()->call('syncNow')->assertDispatched('notify', fn (string $name, array $params): bool => str_contains((string) ($params['message'] ?? ''), 'Could not reach the mail server') && ($params['type'] ?? '') === 'error');
    }

    public function test_check_now_says_when_nothing_is_new(): void
    {
        $this->app->instance(MailAccountProviderFactory::class, $this->factoryFetching(
            fn () => new FetchResult([], ['inbox' => ['uidvalidity' => 1, 'last_uid' => 0, 'backfill_below' => 0]])
        ));

        $this->inbox()->call('syncNow')->assertDispatched('notify', fn (string $name, array $params): bool => ($params['message'] ?? '') === 'You are up to date.');
        $this->assertNotNull($this->account->fresh()->last_synced_at);
    }

    public function test_check_now_cannot_be_hammered(): void
    {
        RateLimiter::clear('mail-sync-now:'.$this->user->id);
        $this->app->instance(MailAccountProviderFactory::class, $this->factoryFetching(
            fn () => new FetchResult([], ['inbox' => ['uidvalidity' => 1, 'last_uid' => 0, 'backfill_below' => 0]])
        ));

        $c = $this->inbox();
        for ($i = 0; $i < 6; $i++) {
            $c->call('syncNow');
        }

        $c->call('syncNow')->assertDispatched('notify', fn (string $name, array $params): bool => str_contains((string) ($params['message'] ?? ''), 'Wait a moment'));
    }

    public function test_a_delivery_failure_notice_is_flagged_in_the_list_and_explained(): void
    {
        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id, 'subject' => 'Undeliverable: Quote']);
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id, 'email_account_id' => $this->account->id,
            'from_email' => 'MAILER-DAEMON@yahoo.com', 'subject' => 'Undeliverable: Quote',
        ]);

        $this->inbox()->assertSee('Delivery failed')
            ->call('selectThread', $thread->id)
            ->assertSee('A message you sent was not delivered.');
    }
}
