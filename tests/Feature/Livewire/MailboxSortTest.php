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

/** The inbox list can be ordered by date, sender or subject, in either direction. */
final class MailboxSortTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('agent');
        $account = EmailAccount::factory()->for($this->user)->create();

        // date order: bravo (oldest) < alpha < charlie (newest); sender order: zed, amy, mia
        $this->thread($account, 'Bravo deal', 'zed@example.com', now()->subDays(3));
        $this->thread($account, 'Alpha deal', 'amy@example.com', now()->subDays(2));
        $this->thread($account, 'Charlie deal', 'mia@example.com', now()->subDay());
    }

    private function thread(EmailAccount $account, string $subject, string $from, \DateTimeInterface $at): void
    {
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id, 'subject' => $subject, 'last_message_at' => $at, 'folder' => 'inbox']);
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id, 'email_account_id' => $account->id, 'from_email' => $from,
            'direction' => EmailMessage::DIRECTION_INBOUND, 'body_text' => 'Body of '.$subject,
        ]);
    }

    /** @return list<string> subjects in the order the list shows them */
    private function order($component): array
    {
        return $component->viewData('threads')->pluck('subject')->all();
    }

    public function test_newest_first_is_the_default_and_the_direction_can_be_reversed(): void
    {
        $c = Livewire::actingAs($this->user)->test(Inbox::class);

        $this->assertSame(['Charlie deal', 'Alpha deal', 'Bravo deal'], $this->order($c));

        $c->call('toggleSortDir');
        $this->assertSame(['Bravo deal', 'Alpha deal', 'Charlie deal'], $this->order($c));
    }

    public function test_sorting_by_subject_is_alphabetical_both_ways(): void
    {
        $c = Livewire::actingAs($this->user)->test(Inbox::class)->set('sortBy', 'subject')->set('sortDir', 'asc');
        $this->assertSame(['Alpha deal', 'Bravo deal', 'Charlie deal'], $this->order($c));

        $c->set('sortDir', 'desc');
        $this->assertSame(['Charlie deal', 'Bravo deal', 'Alpha deal'], $this->order($c));
    }

    public function test_sorting_by_sender_is_alphabetical_by_address(): void
    {
        $c = Livewire::actingAs($this->user)->test(Inbox::class)->set('sortBy', 'sender')->set('sortDir', 'asc');

        $this->assertSame(['Alpha deal', 'Charlie deal', 'Bravo deal'], $this->order($c)); // amy, mia, zed
    }

    public function test_an_unknown_sort_field_or_direction_falls_back_safely(): void
    {
        $c = Livewire::actingAs($this->user)->test(Inbox::class)->set('sortBy', 'id; drop table users')->set('sortDir', 'sideways');

        $this->assertSame(['Charlie deal', 'Alpha deal', 'Bravo deal'], $this->order($c));
    }

    public function test_rows_show_a_preview_line_and_the_list_costs_no_per_row_query(): void
    {
        $c = Livewire::actingAs($this->user)->test(Inbox::class)->assertSee('Body of Alpha deal');

        $this->assertTrue($c->viewData('threads')->first()->relationLoaded('latestMessage'));
    }
}
