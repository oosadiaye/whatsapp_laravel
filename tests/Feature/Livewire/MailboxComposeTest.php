<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\SendUserEmail;
use App\Livewire\Mailbox\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plan B5b — the composer in the inbox seam. Verifies reply/reply-all/forward
 * prefill + threading, fresh compose, the SEND-AS-SELF gate (view_all can read
 * but not reply as another user), validation, and attachment staging.
 */
class MailboxComposeTest extends TestCase
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

    private function accountFor(User $user, string $email = 'me@company.test'): EmailAccount
    {
        return EmailAccount::factory()->for($user)->create([
            'email' => $email,
            'is_active' => true,
        ]);
    }

    private function inboundMessage(EmailAccount $account, array $overrides = []): EmailMessage
    {
        $thread = EmailThread::factory()->create([
            'email_account_id' => $account->id,
            'subject' => 'Project X',
        ]);

        return EmailMessage::factory()->create(array_merge([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'from_email' => 'client@example.com',
            'to' => ['me@company.test'],
            'cc' => ['peer@example.com'],
            'subject' => 'Project X',
            'message_id' => 'parent-abc@example.com',
            'references_header' => '<root@example.com>',
        ], $overrides));
    }

    public function test_reply_prefills_and_dispatches_with_threading(): void
    {
        Bus::fake();
        $me = $this->user();
        $account = $this->accountFor($me);
        $message = $this->inboundMessage($account);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startReply', $message->id)
            ->assertSet('composing', true)
            ->assertSet('composeTo', 'client@example.com')
            ->assertSet('composeSubject', 'Re: Project X')
            ->assertSet('composeInReplyTo', '<parent-abc@example.com>')
            ->set('composeBody', 'Thanks!')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('composing', false);

        Bus::assertDispatched(SendUserEmail::class, function (SendUserEmail $job) use ($account, $message) {
            return $job->accountId === $account->id
                && $job->email->to === ['client@example.com']
                && $job->email->threadId === $message->email_thread_id
                && $job->email->inReplyTo === '<parent-abc@example.com>'
                && str_contains((string) $job->email->references, 'parent-abc@example.com')
                && str_contains((string) $job->email->references, 'root@example.com')
                && $job->email->bodyText === 'Thanks!';
        });
    }

    public function test_reply_all_includes_the_other_recipients_but_not_self(): void
    {
        $me = $this->user();
        $account = $this->accountFor($me);
        $message = $this->inboundMessage($account);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startReply', $message->id, true)
            ->assertSet('composeMode', 'reply_all')
            ->assertSet('composeTo', 'client@example.com, peer@example.com'); // me@company.test dropped
    }

    public function test_forward_opens_a_new_thread_and_quotes_the_original(): void
    {
        Bus::fake();
        $me = $this->user();
        $account = $this->accountFor($me);
        $message = $this->inboundMessage($account);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startForward', $message->id)
            ->assertSet('composeMode', 'forward')
            ->assertSet('composeSubject', 'Fwd: Project X')
            ->assertSet('composeThreadId', null)
            ->set('composeTo', 'someone@else.test')
            ->call('send')
            ->assertHasNoErrors();

        Bus::assertDispatched(SendUserEmail::class, fn (SendUserEmail $job): bool => $job->email->threadId === null
            && $job->email->to === ['someone@else.test']
            && $job->email->inReplyTo === null
            && str_contains((string) $job->email->bodyText, 'Forwarded message') // original quoted
            && str_contains((string) $job->email->bodyText, 'client@example.com'));
    }

    public function test_a_view_all_user_cannot_reply_as_another_user(): void
    {
        Bus::fake();
        $admin = $this->user('admin'); // has mailbox.view_all
        $other = $this->user('agent');
        $otherAccount = $this->accountFor($other, 'other@company.test');
        $message = $this->inboundMessage($otherAccount);

        // Reading is allowed; replying AS them is impersonation and must 403.
        Livewire::actingAs($admin)->test(Inbox::class)
            ->call('startReply', $message->id)
            ->assertStatus(403);

        Bus::assertNothingDispatched();
    }

    public function test_send_requires_a_recipient_and_subject(): void
    {
        Bus::fake();
        $me = $this->user();
        $this->accountFor($me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startCompose')
            ->call('send')
            ->assertHasErrors(['composeTo', 'composeSubject']);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startCompose')
            ->set('composeSubject', 'Hi')
            ->set('composeTo', 'not-an-email')
            ->call('send')
            ->assertHasErrors('composeTo');

        Bus::assertNothingDispatched();
    }

    public function test_compose_stages_uploaded_attachments_to_the_private_disk(): void
    {
        Storage::fake('local');
        Bus::fake();
        $me = $this->user();
        $account = $this->accountFor($me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('startCompose')
            ->set('composeSubject', 'Files')
            ->set('composeTo', 'r@example.test')
            ->set('composeFiles', [UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')])
            ->call('send')
            ->assertHasNoErrors();

        $job = Bus::dispatched(SendUserEmail::class)->first();
        $this->assertNotNull($job);
        $this->assertCount(1, $job->email->attachments);
        $this->assertSame('doc.pdf', $job->email->attachments[0]->filename);
        Storage::disk('local')->assertExists($job->email->attachments[0]->diskPath);
        $this->assertStringStartsWith('mailbox/outbox/'.$account->id.'/', $job->email->attachments[0]->diskPath);
    }
}
