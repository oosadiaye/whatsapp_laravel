<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plan B4 — the inbox thread-shell. Verifies the private-per-user scoping
 * (review M4), search, local mark-read, and that a user can't open another's
 * thread.
 */
class MailboxInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
    }

    private function user(string $role = 'agent'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function threadFor(User $user, array $attrs = [], int $unread = 1): EmailThread
    {
        $account = EmailAccount::factory()->for($user)->create();
        $thread = EmailThread::factory()->create(array_merge([
            'email_account_id' => $account->id,
            'unread_count' => $unread,
        ], $attrs));

        EmailMessage::factory()->count(max(1, $unread))->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'is_read' => false,
        ]);

        return $thread;
    }

    public function test_a_user_sees_only_their_own_threads(): void
    {
        $me = $this->user();
        $this->threadFor($me, ['subject' => 'My private thread']);
        $this->threadFor($this->user(), ['subject' => 'Someone elses thread']);

        Livewire::actingAs($me)->test(Inbox::class)
            ->assertSee('My private thread')
            ->assertDontSee('Someone elses thread');
    }

    public function test_view_all_sees_the_whole_team(): void
    {
        $admin = $this->user('admin'); // has mailbox.view_all
        $this->threadFor($admin, ['subject' => 'Admin own thread']);
        $this->threadFor($this->user(), ['subject' => 'Agent thread visible to admin']);

        Livewire::actingAs($admin)->test(Inbox::class)
            ->assertSee('Admin own thread')
            ->assertSee('Agent thread visible to admin');
    }

    public function test_search_filters_the_thread_list(): void
    {
        $me = $this->user();
        $this->threadFor($me, ['subject' => 'Invoice October 2026']);
        $this->threadFor($me, ['subject' => 'Team lunch plans']);

        Livewire::actingAs($me)->test(Inbox::class)
            ->set('search', 'Invoice')
            ->assertSee('Invoice October 2026')
            ->assertDontSee('Team lunch plans');
    }

    public function test_selecting_a_thread_marks_it_read(): void
    {
        $me = $this->user();
        $thread = $this->threadFor($me, ['subject' => 'Unread thread'], unread: 2);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('selectThread', $thread->id)
            ->assertSet('selectedThreadId', $thread->id);

        $this->assertSame(0, $thread->fresh()->unread_count);
        $this->assertSame(0, $thread->messages()->where('is_read', false)->count());
    }

    public function test_thread_messages_render_in_event_time_order(): void
    {
        $me = $this->user();
        $account = EmailAccount::factory()->for($me)->create(['email' => 'me@company.test']);
        $thread = EmailThread::factory()->create([
            'email_account_id' => $account->id,
            'unread_count' => 0,
        ]);

        // Inbound arrived yesterday.
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'from_email' => 'client@example.test',
            'received_at' => now()->subDay(),
            'sent_at' => null,
            'is_read' => true,
        ]);
        // Our reply sent today — outbound has received_at NULL, which a naive
        // orderBy('received_at') would float to the TOP of the thread.
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'from_email' => 'me@company.test',
            'received_at' => null,
            'sent_at' => now(),
            'is_read' => true,
        ]);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('selectThread', $thread->id)
            ->assertSeeHtmlInOrder(['client@example.test', 'me@company.test']);
    }

    public function test_a_user_cannot_open_another_users_thread(): void
    {
        $me = $this->user();
        $theirs = $this->threadFor($this->user());

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('selectThread', $theirs->id)
            ->assertStatus(404);
    }

    public function test_inbox_route_is_absent_when_the_feature_is_disabled(): void
    {
        config(['mail_client.enabled' => false]);

        $this->actingAs($this->user())->get(route('mailbox.inbox'))->assertNotFound();
    }
}
