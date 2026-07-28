<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Services\MailClient\ConnectionResult;
use App\Services\MailClient\GmailProvider;
use App\Services\MailClient\GraphProvider;
use App\Services\MailClient\ImapSmtpProvider;
use App\Services\MailClient\MailAccountProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Plan B2 — the mail-account provider layer. The IMAP client is injectable so
 * the connection check is verified WITHOUT a live server.
 */
class MailboxProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_resolves_imap_and_returns_null_for_unknown(): void
    {
        $factory = new MailAccountProviderFactory();

        $this->assertInstanceOf(ImapSmtpProvider::class, $factory->make(EmailAccount::PROVIDER_IMAP));
        $this->assertNull($factory->make('nope'));
    }

    public function test_connection_test_fails_on_missing_credentials(): void
    {
        $account = EmailAccount::factory()->make(['credentials' => ['imap_host' => '']]);

        $result = (new ImapSmtpProvider())->connectionTest($account);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Missing IMAP credential', (string) $result->error);
    }

    public function test_connection_test_ok_when_the_client_connects(): void
    {
        $account = EmailAccount::factory()->make(['credentials' => [
            'imap_host' => 'imap.test', 'username' => 'u', 'password' => 'p',
        ]]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andReturnSelf();
        $client->shouldReceive('disconnect')->once();
        $manager = Mockery::mock(ClientManager::class);
        $manager->shouldReceive('make')->once()->andReturn($client);

        $result = (new ImapSmtpProvider($manager))->connectionTest($account);

        $this->assertTrue($result->ok);
    }

    public function test_connection_test_fails_and_reports_the_reason_when_the_client_throws(): void
    {
        $account = EmailAccount::factory()->make(['credentials' => [
            'imap_host' => 'imap.test', 'username' => 'u', 'password' => 'p',
        ]]);

        $manager = Mockery::mock(ClientManager::class);
        $manager->shouldReceive('make')->andThrow(new \RuntimeException('IMAP auth failed'));

        $result = (new ImapSmtpProvider($manager))->connectionTest($account);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('IMAP auth failed', (string) $result->error);
    }

    /**
     * Delegate ImapSmtpProvider to a stub so we assert routing, not a live connect.
     */
    private function stubImapDelegate(): void
    {
        $this->app->bind(ImapSmtpProvider::class, fn () => new class extends ImapSmtpProvider {
            public function connectionTest(EmailAccount $account): ConnectionResult
            {
                return ConnectionResult::ok(); // reaching here proves delegation
            }
        });
    }

    public function test_gmail_provider_routes_non_oauth_credentials_to_imap(): void
    {
        // M2 regression guard: `$creds['auth_type'] ?? '' === 'oauth'` used to
        // parse as `?? false`, so a truthy non-'oauth' value fell through to the
        // IMAP path by accident — but any future auth_type would misroute. A
        // 'password' account MUST delegate to IMAP, not enter testOAuth.
        $this->stubImapDelegate();
        $account = EmailAccount::factory()->make([
            'provider' => EmailAccount::PROVIDER_GMAIL,
            'credentials' => ['auth_type' => 'password', 'imap_host' => 'imap.gmail.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->assertTrue((new GmailProvider())->connectionTest($account)->ok);
    }

    public function test_graph_provider_routes_non_oauth_credentials_to_imap(): void
    {
        $this->stubImapDelegate();
        $account = EmailAccount::factory()->make([
            'provider' => EmailAccount::PROVIDER_GRAPH,
            'credentials' => ['auth_type' => 'password', 'imap_host' => 'outlook.office365.com', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->assertTrue((new GraphProvider())->connectionTest($account)->ok);
    }

    public function test_gmail_oauth_path_is_reachable_and_fails_without_a_token(): void
    {
        // The other side of the M2 fix: an explicit 'oauth' account DOES take the
        // OAuth branch (and fails cleanly when no access token is present).
        $account = EmailAccount::factory()->make([
            'provider' => EmailAccount::PROVIDER_GMAIL,
            'credentials' => ['auth_type' => 'oauth'],
        ]);

        $result = (new GmailProvider())->connectionTest($account);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('OAuth access token', (string) $result->error);
    }
}
