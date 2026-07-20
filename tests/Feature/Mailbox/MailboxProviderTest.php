<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
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
}
