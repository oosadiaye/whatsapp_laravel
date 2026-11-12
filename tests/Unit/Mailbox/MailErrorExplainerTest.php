<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbox;

use App\Services\MailClient\MailErrorExplainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Raw server/library errors become sentences a person can act on. */
final class MailErrorExplainerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'bad password' => ['Expected response code "250" but got code "535", with message "535 5.7.8 Authentication failed"', 'username or password'],
            'gmail app password' => ['Username and Password not accepted. Learn more at https://support.google.com', 'username or password'],
            'unreachable host' => ['Connection could not be established with host "smtp.x.test:587": stream_socket_client(): Unable to connect', 'reach'],
            'imap library' => ['connection failed', 'reach'],
            'timeout' => ['Connection to "imap.x.test:993" timed out', 'reach'],
            'dns' => ['php_network_getaddresses: getaddrinfo failed: Name or service not known', 'reach'],
            'tls' => ['Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed; certificate verify failed', 'secure connection'],
            'recipient refused' => ['Expected response code "250/251/252" but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist"', 'recipient'],
            'too big' => ['Expected response code "250" but got code "552", with message "552 message size exceeds limit"', 'too large'],
            'rate' => ['Expected response code "250" but got code "421", with message "421 4.7.0 Too many messages, try again later"', 'try again'],
            'spam block' => ['Expected response code "250" but got code "554", with message "554 5.7.1 Message blocked as spam"', 'spam'],
        ];
    }

    #[DataProvider('cases')]
    public function test_a_known_failure_is_explained_in_plain_language(string $raw, string $expected): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException($raw));

        $this->assertStringContainsStringIgnoringCase($expected, $text);
        $this->assertStringNotContainsString('stream_socket', $text);
    }

    public function test_yahoo_and_gmail_users_are_told_about_app_passwords(): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException('535 Authentication failed'), 'imap.mail.yahoo.com');

        $this->assertStringContainsStringIgnoringCase('app password', $text);
    }

    public function test_an_unknown_failure_is_generic_and_never_repeats_the_server_text(): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException('220 internal-db.corp ESMTP banner secret-hostname'));

        $this->assertStringContainsString('could not identify', $text);
        $this->assertStringNotContainsString('secret-hostname', $text);
        $this->assertLessThanOrEqual(500, mb_strlen($text));
    }

    public function test_gmail_app_password_replies_are_not_mistaken_for_spam_blocks(): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException('534-5.7.9 Application-specific password required.'), 'smtp.gmail.com');

        $this->assertStringContainsStringIgnoringCase('app password', $text);
        $this->assertStringNotContainsStringIgnoringCase('spam', $text);
    }

    public function test_a_port_number_is_not_read_as_a_temporary_error_code(): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException('something odd on port 465'));

        $this->assertStringNotContainsStringIgnoringCase('temporarily', $text);
    }

    public function test_it_never_echoes_a_password_style_secret(): void
    {
        $text = MailErrorExplainer::explain(new RuntimeException('AUTH PLAIN failed password=hunter2'));

        $this->assertStringNotContainsString('hunter2', $text);
    }
}
