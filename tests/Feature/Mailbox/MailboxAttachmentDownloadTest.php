<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan B5b — inbound attachment downloads. The binary lives on the private disk
 * (outside the web root), so every request is re-authorized: own account, or any
 * account with mailbox.view_all; blocked otherwise and when the feature is off.
 */
class MailboxAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
        Storage::fake('local');
    }

    private function user(string $role = 'agent'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function attachmentFor(User $owner): EmailAttachment
    {
        $account = EmailAccount::factory()->for($owner)->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
        ]);

        $path = 'email-attachments/'.$account->id.'/file.txt';
        Storage::disk('local')->put($path, 'HELLO');

        return EmailAttachment::create([
            'email_message_id' => $message->id,
            'filename' => 'file.txt',
            'mime' => 'text/plain',
            'size' => 5,
            'path' => $path,
        ]);
    }

    public function test_the_owner_can_download_the_attachment(): void
    {
        $owner = $this->user();
        $attachment = $this->attachmentFor($owner);

        $this->actingAs($owner)
            ->get(route('mailbox.attachments.download', $attachment))
            ->assertOk()
            ->assertDownload('file.txt');
    }

    public function test_another_user_without_view_all_is_forbidden(): void
    {
        $owner = $this->user();
        $attachment = $this->attachmentFor($owner);

        $this->actingAs($this->user())
            ->get(route('mailbox.attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_a_view_all_user_can_download_any_attachment(): void
    {
        $owner = $this->user();
        $attachment = $this->attachmentFor($owner);

        $this->actingAs($this->user('admin')) // mailbox.view_all
            ->get(route('mailbox.attachments.download', $attachment))
            ->assertOk();
    }

    public function test_the_route_is_absent_when_the_feature_is_disabled(): void
    {
        $owner = $this->user();
        $attachment = $this->attachmentFor($owner);
        config(['mail_client.enabled' => false]);

        $this->actingAs($owner)
            ->get(route('mailbox.attachments.download', $attachment))
            ->assertNotFound();
    }
}
