<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Events\Mailbox\MailReceived;
use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\MailClient\EmailSyncService;
use App\Services\MailClient\FetchedMessage;
use App\Services\MailClient\FetchResult;
use App\Services\MailClient\MailFetcher;
use App\Services\MailClient\SentFolderFinder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** Inbox holds what came in; Sent holds everything this mailbox sent, from the app or elsewhere. */
final class SentFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
    }

    private function fetched(string $id, string $folder, string $from, array $to, ?string $inReplyTo = null, ?\DateTimeInterface $date = null): FetchedMessage
    {
        return new FetchedMessage(
            messageId: $id, inReplyTo: $inReplyTo, references: $inReplyTo, from: $from, to: $to, cc: [],
            subject: 'Quote', bodyHtml: null, bodyText: 'Hello', date: $date ?? now(), folder: $folder,
        );
    }

    private function sync(EmailAccount $account, FetchedMessage ...$messages): void
    {
        $fetcher = new class(new FetchResult($messages, ['inbox' => ['uidvalidity' => 1, 'last_uid' => 9]])) implements MailFetcher
        {
            public function __construct(private readonly FetchResult $result) {}

            public function fetch(EmailAccount $account): FetchResult
            {
                return $this->result;
            }
        };

        (new EmailSyncService)->sync($account, $fetcher);
    }

    public function test_the_sent_folder_picker_knows_the_common_names(): void
    {
        $this->assertSame('[Gmail]/Sent Mail', SentFolderFinder::pick(['INBOX', '[Gmail]/Drafts', '[Gmail]/Sent Mail']));
        $this->assertSame('Sent Items', SentFolderFinder::pick(['INBOX', 'Sent Items', 'Deleted Items']));
        $this->assertSame('INBOX.Sent', SentFolderFinder::pick(['INBOX', 'INBOX.Sent', 'INBOX.Trash']));
        $this->assertNull(SentFolderFinder::pick(['INBOX', 'Drafts']));
    }

    public function test_mail_from_the_sent_folder_is_stored_as_sent_read_and_silent(): void
    {
        Event::fake([MailReceived::class]);
        $account = EmailAccount::factory()->create(['email' => 'me@work.test']);

        $this->sync($account, $this->fetched('<out@work.test>', 'sent', 'me@work.test', ['ann@example.com']));

        $message = EmailMessage::firstOrFail();
        $this->assertSame(EmailMessage::DIRECTION_OUTBOUND, $message->direction);
        $this->assertTrue($message->is_read);
        $this->assertNull($message->received_at);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(EmailThread::FOLDER_SENT, $message->thread->folder);
        $this->assertSame(0, $message->thread->unread_count);
        Event::assertNotDispatched(MailReceived::class);
    }

    public function test_the_same_message_sent_from_the_app_and_found_in_sent_is_stored_once(): void
    {
        $account = EmailAccount::factory()->create(['email' => 'me@work.test']);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id, 'folder' => 'sent']);
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id, 'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND, 'message_id' => 'dup@work.test',
        ]);

        $this->sync($account, $this->fetched('<dup@work.test>', 'sent', 'me@work.test', ['ann@example.com']));

        $this->assertSame(1, EmailMessage::count());
    }

    public function test_an_answer_to_something_we_sent_moves_the_conversation_to_the_inbox(): void
    {
        $account = EmailAccount::factory()->create(['email' => 'me@work.test']);
        $this->sync($account, $this->fetched('<out@work.test>', 'sent', 'me@work.test', ['ann@example.com'], date: now()->subHour()));
        $thread = EmailThread::firstOrFail();
        $this->assertSame('sent', $thread->folder);

        $this->sync($account->fresh(), $this->fetched('<back@example.com>', 'inbox', 'ann@example.com', ['me@work.test'], '<out@work.test>'));

        $thread->refresh();
        $this->assertSame('inbox', $thread->folder);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame(1, EmailThread::count());
    }

    public function test_an_older_sent_message_does_not_move_a_thread_backwards(): void
    {
        $account = EmailAccount::factory()->create(['email' => 'me@work.test']);
        $this->sync($account, $this->fetched('<in@example.com>', 'inbox', 'ann@example.com', ['me@work.test'], date: now()));
        $latest = EmailThread::firstOrFail()->last_message_at;

        $this->sync($account->fresh(), $this->fetched('<old@work.test>', 'sent', 'me@work.test', ['ann@example.com'], '<in@example.com>', now()->subDays(5)));

        $this->assertTrue(EmailThread::firstOrFail()->last_message_at->equalTo($latest));
    }

    public function test_the_sent_tab_lists_a_reply_sent_from_an_inbox_thread_and_the_inbox_still_lists_it(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('agent');
        $account = EmailAccount::factory()->for($user)->create();
        $replied = EmailThread::factory()->create(['email_account_id' => $account->id, 'folder' => 'inbox', 'subject' => 'Replied thread']);
        EmailMessage::factory()->create(['email_thread_id' => $replied->id, 'email_account_id' => $account->id, 'direction' => EmailMessage::DIRECTION_OUTBOUND, 'to' => ['ann@example.com']]);
        $untouched = EmailThread::factory()->create(['email_account_id' => $account->id, 'folder' => 'inbox', 'subject' => 'Never answered']);
        EmailMessage::factory()->create(['email_thread_id' => $untouched->id, 'email_account_id' => $account->id, 'direction' => EmailMessage::DIRECTION_INBOUND]);
        $trashed = EmailThread::factory()->create(['email_account_id' => $account->id, 'folder' => 'trash', 'subject' => 'Trashed']);
        EmailMessage::factory()->create(['email_thread_id' => $trashed->id, 'email_account_id' => $account->id, 'direction' => EmailMessage::DIRECTION_OUTBOUND]);

        Livewire::actingAs($user)->test(Inbox::class)
            ->assertSee('Never answered')
            ->set('folder', 'sent')
            ->assertSee('To: ann@example.com')
            ->assertDontSee('Never answered')
            ->assertDontSee('Trashed')
            ->set('folder', 'inbox')
            ->assertSee('Replied thread');
    }
}
