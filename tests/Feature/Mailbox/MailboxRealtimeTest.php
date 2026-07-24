<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Events\Mailbox\MailReceived;
use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan B6 — realtime push. Verifies the new-mail event targets the OWNER's
 * user-scoped private channel with the alias the JS/Livewire listener expects,
 * and that the inbox subscribes on the current user's channel.
 */
class MailboxRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
    }

    public function test_mail_received_broadcasts_on_the_owner_channel_with_alias(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'from_email' => 'sender@example.test',
            'subject' => 'Hi there',
        ]);

        $event = new MailReceived($account, $message);

        $this->assertStringContainsString('user.'.$user->id, $event->broadcastOn()->name);
        $this->assertSame('mail.received', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($account->id, $payload['account_id']);
        $this->assertSame($thread->id, $payload['thread_id']);
        $this->assertSame('sender@example.test', $payload['from']);
    }

    public function test_the_inbox_listens_on_the_current_users_mail_channel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('agent');
        $this->actingAs($user);

        $inbox = new Inbox();
        // Bind a closure to the component to reach its protected getListeners().
        $listeners = (fn () => $this->getListeners())->call($inbox);

        $key = "echo-private:user.{$user->id},.mail.received";
        $this->assertArrayHasKey($key, $listeners);
        $this->assertSame('$refresh', $listeners[$key]);
    }
}
