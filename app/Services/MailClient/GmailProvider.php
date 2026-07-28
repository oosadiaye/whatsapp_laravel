<?php

declare(strict_types=1);

namespace App\Services\MailClient;

use App\Models\EmailAccount;

class GmailProvider implements MailAccountProvider
{
    public function connectionTest(EmailAccount $account): ConnectionResult
    {
        $creds = $account->credentials ?? [];

        // Parenthesised: `===` binds tighter than `??`, so the un-parenthesised
        // form evaluated as `$creds['auth_type'] ?? ('' === 'oauth')` and any
        // non-'oauth' truthy value wrongly routed into testOAuth (M2).
        if (($creds['auth_type'] ?? '') === 'oauth') {
            return $this->testOAuth($creds);
        }

        return app(ImapSmtpProvider::class)->connectionTest($account);
    }

    private function testOAuth(array $creds): ConnectionResult
    {
        if (blank($creds['access_token'] ?? null)) {
            return ConnectionResult::fail('OAuth access token missing or expired.');
        }

        return ConnectionResult::ok();
    }

    public static function imapPresets(): array
    {
        return [
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ];
    }
}
