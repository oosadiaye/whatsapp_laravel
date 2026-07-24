<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\MailClient\ConnectionResult;
use App\Services\MailClient\MailAccountProvider;
use App\Services\MailClient\MailAccountProviderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan B2 — the account-connection flow: flag-gated, per-user scoped, and only
 * activated when the credentials actually sign in. The provider is stubbed so
 * connectionTest doesn't need a live IMAP server.
 */
class MailboxAccountTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function validForm(): array
    {
        return [
            'email' => 'me@work.test',
            'display_name' => 'Me',
            'imap_host' => 'imap.work.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.work.test', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'username' => 'me@work.test', 'password' => 'secret',
        ];
    }

    private function stubProvider(bool $ok): void
    {
        $this->app->bind(MailAccountProviderFactory::class, function () use ($ok) {
            return new class($ok) extends MailAccountProviderFactory {
                public function __construct(private readonly bool $ok)
                {
                }

                public function for(EmailAccount $account): ?MailAccountProvider
                {
                    $ok = $this->ok;

                    return new class($ok) implements MailAccountProvider {
                        public function __construct(private readonly bool $ok)
                        {
                        }

                        public function connectionTest(EmailAccount $account): ConnectionResult
                        {
                            return $this->ok ? ConnectionResult::ok() : ConnectionResult::fail('bad creds');
                        }
                    };
                }
            };
        });
    }

    public function test_routes_are_absent_when_the_feature_is_disabled(): void
    {
        config(['mail_client.enabled' => false]);

        $this->actingAs($this->user())->get(route('mailbox.accounts.index'))->assertNotFound();
    }

    public function test_a_user_connects_a_mailbox_when_credentials_verify(): void
    {
        $this->stubProvider(ok: true);
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('mailbox.accounts.store'), $this->validForm())
            ->assertRedirect(route('mailbox.accounts.index'));

        $account = EmailAccount::where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('me@work.test', $account->email);
        $this->assertTrue($account->is_active);
        $this->assertFalse($account->needs_reauth);
    }

    public function test_a_failed_sign_in_saves_the_account_but_flags_reauth(): void
    {
        $this->stubProvider(ok: false);
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('mailbox.accounts.store'), $this->validForm())
            ->assertSessionHas('error');

        $account = EmailAccount::where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertFalse($account->is_active);
        $this->assertTrue($account->needs_reauth);
    }

    public function test_a_user_cannot_disconnect_another_users_mailbox(): void
    {
        $owner = $this->user();
        $account = EmailAccount::factory()->for($owner)->create();
        $other = $this->user();

        $this->actingAs($other)
            ->delete(route('mailbox.accounts.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('email_accounts', ['id' => $account->id, 'deleted_at' => null]);
    }

    public function test_owner_can_disconnect_their_own_mailbox(): void
    {
        $owner = $this->user();
        $account = EmailAccount::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->delete(route('mailbox.accounts.destroy', $account))
            ->assertRedirect(route('mailbox.accounts.index'));

        $this->assertSoftDeleted('email_accounts', ['id' => $account->id]);
    }
}
