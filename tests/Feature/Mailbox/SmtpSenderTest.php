<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\EmailAccount;
use App\Services\MailClient\OutboundEmail;
use App\Services\MailClient\SmtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression guard for the SMTP encryption dead-config bug: Laravel derives the
 * transport TLS scheme from the mailer config `scheme` (falling back to the port
 * only when absent), so SmtpSender MUST map the account's "Encryption" dropdown
 * (ssl/tls/none) onto a real scheme — otherwise the operator's choice is ignored.
 */
class SmtpSenderTest extends TestCase
{
    use RefreshDatabase;

    private function sendWith(string $encryption): EmailAccount
    {
        Mail::fake();
        $account = EmailAccount::factory()->create([
            'is_active' => true,
            'credentials' => [
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_encryption' => $encryption,
                'username' => 'me@example.com',
                'password' => 'secret',
            ],
        ]);

        (new SmtpSender)->send($account, new OutboundEmail(
            to: ['to@example.com'],
            subject: 'Subject',
            bodyHtml: '<p>Hi</p>',
            bodyText: 'Hi',
            cc: [],
            inReplyTo: null,
            references: null,
            threadId: null,
            attachments: [],
        ));

        return $account;
    }

    public function test_none_maps_to_plaintext_with_auto_tls_disabled(): void
    {
        $account = $this->sendWith('none');

        $config = config('mail.mailers.mailbox_'.$account->id);
        $this->assertSame('smtp', $config['scheme']);   // not implicit smtps
        $this->assertFalse($config['auto_tls']);        // no opportunistic upgrade
    }

    public function test_tls_maps_to_smtp_scheme(): void
    {
        $account = $this->sendWith('tls');

        $config = config('mail.mailers.mailbox_'.$account->id);
        $this->assertSame('smtp', $config['scheme']);   // STARTTLS on port 587
        $this->assertArrayNotHasKey('auto_tls', $config);
    }

    public function test_ssl_maps_to_smtps_scheme_even_on_a_non_465_port(): void
    {
        // The regression: on port 587 + "ssl", the port-based fallback produced
        // STARTTLS instead of implicit TLS. An explicit scheme fixes that.
        $account = $this->sendWith('ssl');

        $config = config('mail.mailers.mailbox_'.$account->id);
        $this->assertSame('smtps', $config['scheme']);
    }
}
