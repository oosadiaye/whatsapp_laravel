<?php

declare(strict_types=1);

namespace App\Services\MailClient;

/**
 * Turns the raw error from a mail server or the mail library into one sentence a person can act on.
 * Used wherever a mailbox problem is shown to someone (sync status, a message that did not send);
 * the raw text still goes to the server log.
 */
final class MailErrorExplainer
{
    /** Hosts whose accounts need an app password rather than the normal login password. */
    private const APP_PASSWORD_HOSTS = ['yahoo', 'gmail', 'google', 'icloud', 'me.com', 'aol'];

    public static function explain(\Throwable|string $error, ?string $host = null): string
    {
        $raw = self::scrub($error instanceof \Throwable ? $error->getMessage() : $error);
        $lower = strtolower($raw);

        if (preg_match('/\b53[045]\b|authentication (?:failed|required|unsuccessful)|auth(?:entication)? failed|application-specific password|app password|username and password not accepted|invalid credentials|login failed|authenticationfailed/', $lower)) {
            $hint = self::needsAppPassword($host)
                ? ' This provider needs an app password (made in its security settings), not your normal login password.'
                : '';

            return 'The mail server did not accept the username or password.'.$hint.' Open the mailbox settings and check them.';
        }

        if (preg_match('/certificate|starttls|ssl operation failed|tls|crypto/', $lower)) {
            return 'A secure connection to the mail server could not be set up. Check that the port and the encryption setting (SSL or TLS) match your provider.';
        }

        if (preg_match('/could not be established|connection failed|failed to connect|cannot connect|unable to connect|timed out|timeout|connection (?:refused|reset|closed)|getaddrinfo|name or service not known|no route to host|network is unreachable/', $lower)) {
            return 'Could not reach the mail server. Check the server address and port in the mailbox settings, then try again.';
        }

        if (preg_match('/\b552\b|message size|too large|exceeds.*limit|size limit/', $lower)) {
            return 'The message is too large for the mail server. Remove or shrink attachments and send again.';
        }

        if (preg_match('/spam|blocked|blacklist|blocklist|rejected for policy|\b554\b|\b5\.7\.\d\b/', $lower)) {
            return 'The receiving server refused the message as spam or for policy reasons. Rephrase it or contact the recipient another way.';
        }

        if (preg_match('/\b55[0-3]\b|\b5\.1\.\d\b|does not exist|no such user|user unknown|mailbox unavailable|invalid recipient|recipient/', $lower)) {
            return 'The recipient address was refused, usually because it does not exist or is mistyped. Check the address and send again.';
        }

        if (preg_match('/\b4(?:21|50|51|52)\b|try again|too many|rate limit|temporar/', $lower)) {
            return 'The mail server is limiting or temporarily refusing mail. Wait a few minutes and try again.';
        }

        // Anything unrecognised is NOT repeated to the person: a server's own reply can reveal what
        // sits behind a mistyped or hostile host name. The raw text goes to the server log instead.
        return 'The mail server reported a problem we could not identify. Check the mailbox settings, or ask your administrator to look at the server log.';
    }

    private static function needsAppPassword(?string $host): bool
    {
        $host = strtolower((string) $host);

        foreach (self::APP_PASSWORD_HOSTS as $needle) {
            if ($host !== '' && str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Library errors can echo the login line; never pass a password-looking value on. */
    private static function scrub(string $text): string
    {
        return (string) preg_replace('/(pass(?:word)?\s*[=:]\s*)\S+/i', '$1[hidden]', $text);
    }
}
