<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;

/**
 * Generic IMAP (+ SMTP for sending, used in B5) mail-account adapter (plan B2),
 * backed by the pure-PHP webklex/php-imap client (no ext-imap needed).
 *
 * The `ClientManager` is injectable so the connection can be mocked in tests
 * without a live server (the rest of the codebase depends on our contract, not
 * the library — php/patterns "wrap third-party SDKs behind small adapters").
 *
 * Expected `credentials` shape (encrypted at rest on EmailAccount):
 *   imap_host, imap_port, imap_encryption (ssl|tls|starttls|''),
 *   smtp_host, smtp_port, smtp_encryption,  (used by the B5 send path)
 *   username, password.
 */
class ImapSmtpProvider implements MailAccountProvider
{
    public function __construct(private readonly ?ClientManager $clientManager = null)
    {
    }

    public function connectionTest(EmailAccount $account): ConnectionResult
    {
        $creds = $account->credentials ?? [];

        foreach (['imap_host', 'username', 'password'] as $required) {
            if (blank($creds[$required] ?? null)) {
                return ConnectionResult::fail("Missing IMAP credential: {$required}.");
            }
        }

        try {
            $client = $this->manager()->make([
                'host' => (string) $creds['imap_host'],
                'port' => (int) ($creds['imap_port'] ?? 993),
                'encryption' => ($creds['imap_encryption'] ?? 'ssl') ?: false,
                'validate_cert' => (bool) ($creds['validate_cert'] ?? true),
                'username' => (string) $creds['username'],
                'password' => (string) $creds['password'],
                'protocol' => 'imap',
            ]);

            $client->connect();
            $client->disconnect();

            return ConnectionResult::ok();
        } catch (\Throwable $e) {
            // Never leak the password; the message is provider text (host/auth).
            return ConnectionResult::fail($e->getMessage());
        }
    }

    private function manager(): ClientManager
    {
        return $this->clientManager ?? new ClientManager();
    }
}
