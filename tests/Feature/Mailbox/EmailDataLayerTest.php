<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan B1 — the email-client data layer. Additive + inert while the feature flag
 * is off; the one security-critical property is that connected-account
 * credentials never serialize to the client (plan H1).
 */
class EmailDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_is_flag_gated_off_by_default(): void
    {
        $this->assertFalse((bool) config('mail_client.enabled'));
    }

    public function test_account_thread_message_attachment_relate(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
        ]);
        EmailAttachment::factory()->create(['email_message_id' => $message->id]);

        $this->assertSame($user->id, $account->user->id);
        $this->assertTrue($account->threads->contains($thread));
        $this->assertTrue($thread->messages->contains($message));
        $this->assertSame($account->id, $message->account->id);
        $this->assertCount(1, $message->fresh()->attachments);
    }

    public function test_credentials_are_encrypted_at_rest_and_never_serialized(): void
    {
        $account = EmailAccount::factory()->create([
            'credentials' => ['type' => 'oauth', 'refresh_token' => 'super-secret-token'],
        ]);

        // Round-trips as an array in-process (usable by the sync/send jobs).
        $this->assertSame('super-secret-token', $account->fresh()->credentials['refresh_token']);

        // But NEVER leaves via array/JSON serialization (Livewire/toArray) — H1.
        $this->assertArrayNotHasKey('credentials', $account->toArray());
        $this->assertStringNotContainsString('super-secret-token', (string) json_encode($account->toArray()));

        // And it's stored encrypted at rest, not plaintext.
        $raw = DB::table('email_accounts')->where('id', $account->id)->value('credentials');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-secret-token', (string) $raw);
    }

    public function test_reconnecting_a_disconnected_account_revives_it(): void
    {
        // Reuses the contact soft-delete/unique trap fix: a reconnect must revive
        // the trashed row, not collide on unique(user_id, email).
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['email' => 'me@work.test']);
        $account->delete();
        $this->assertSoftDeleted('email_accounts', ['id' => $account->id]);

        $resolved = EmailAccount::firstOrNewIncludingTrashed([
            'user_id' => $user->id,
            'email' => 'me@work.test',
        ]);

        $this->assertTrue($resolved->exists);
        $this->assertSame($account->id, $resolved->id);
        $this->assertFalse($resolved->trashed());
        $this->assertSame(1, EmailAccount::withTrashed()->where('email', 'me@work.test')->count());
    }
}
