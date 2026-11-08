<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\Ai\AiClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ScriptedAiClient;
use Tests\TestCase;

/** Each person sets their own signature per mailbox; it is placed in new mail, replies and forwards. */
final class MailboxSignatureTest extends TestCase
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
        $this->account = EmailAccount::factory()->for($this->user)->create([
            'is_active' => true, 'signature' => "Best regards,\nAda Obi", 'signature_auto' => true,
        ]);
    }

    private function inbound(): EmailMessage
    {
        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id, 'subject' => 'Hello']);

        return EmailMessage::factory()->create([
            'email_thread_id' => $thread->id, 'email_account_id' => $this->account->id,
            'direction' => EmailMessage::DIRECTION_INBOUND, 'from_email' => 'client@example.com', 'to' => ['me@x.test'], 'body_text' => 'Original',
        ]);
    }

    public function test_a_new_message_opens_with_the_signature_below_the_cursor(): void
    {
        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('startCompose')
            ->assertSet('composeBody', "\n\n-- \nBest regards,\nAda Obi");
    }

    public function test_replies_and_forwards_get_it_too_and_a_forward_puts_it_above_the_quoted_text(): void
    {
        $message = $this->inbound();

        $c = Livewire::actingAs($this->user)->test(Inbox::class)->call('startReply', $message->id);
        $c->assertSet('composeBody', "\n\n-- \nBest regards,\nAda Obi");

        $c = Livewire::actingAs($this->user)->test(Inbox::class)->call('startForward', $message->id);
        $body = $c->get('composeBody');
        $this->assertLessThan(strpos($body, 'Forwarded message'), strpos($body, 'Ada Obi'));
    }

    public function test_no_signature_when_it_is_off_or_empty(): void
    {
        $this->account->update(['signature_auto' => false]);
        Livewire::actingAs($this->user)->test(Inbox::class)->call('startCompose')->assertSet('composeBody', '');

        $this->account->update(['signature_auto' => true, 'signature' => null]);
        Livewire::actingAs($this->user)->test(Inbox::class)->call('startCompose')->assertSet('composeBody', '');
    }

    public function test_switching_the_sending_mailbox_swaps_the_signature(): void
    {
        $other = EmailAccount::factory()->for($this->user)->create([
            'is_active' => true, 'email' => 'z-second@work.test', 'signature' => 'Ada, Sales', 'signature_auto' => true,
        ]);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('startCompose')
            ->set('composeBody', "Hi there\n\n-- \nBest regards,\nAda Obi")
            ->set('composeAccountId', $other->id)
            ->assertSet('composeBody', "Hi there\n\n-- \nAda, Sales");
    }

    public function test_the_ai_editor_never_rewrites_the_signature(): void
    {
        $client = new ScriptedAiClient(['Fixed text.']);
        $this->app->instance(AiClient::class, $client);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('startCompose')
            ->set('composeBody', "Fixd text.\n\n-- \nBest regards,\nAda Obi")
            ->call('aiEdit', 'fix')
            ->assertSet('composeBody', "Fixed text.\n\n-- \nBest regards,\nAda Obi");

        $this->assertStringNotContainsString('Ada Obi', json_encode($client->calls));
    }

    public function test_an_owner_saves_their_signature(): void
    {
        $this->actingAs($this->user)
            ->put(route('mailbox.accounts.signature', $this->account), ['signature' => "  Warm wishes\r\nAda  ", 'signature_auto' => 0])
            ->assertRedirect(route('mailbox.accounts.edit', $this->account));

        $fresh = $this->account->fresh();
        $this->assertSame("Warm wishes\nAda", $fresh->signature);
        $this->assertFalse($fresh->signature_auto);
    }

    public function test_nobody_can_set_another_persons_signature(): void
    {
        $intruder = User::factory()->create(['is_active' => true]);
        $intruder->assignRole('agent');

        $this->actingAs($intruder)
            ->put(route('mailbox.accounts.signature', $this->account), ['signature' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame("Best regards,\nAda Obi", $this->account->fresh()->signature);
    }

    public function test_an_overlong_signature_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->put(route('mailbox.accounts.signature', $this->account), ['signature' => str_repeat('x', 1001)])
            ->assertSessionHasErrors('signature');
    }

    public function test_the_edit_page_shows_the_signature_form(): void
    {
        $this->actingAs($this->user)->get(route('mailbox.accounts.edit', $this->account))
            ->assertOk()->assertSee('Email signature')->assertSee('Ada Obi');
    }
}
