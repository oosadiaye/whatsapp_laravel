<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Resolves the outbound mail transport from a single place so bulk email
 * campaigns can be configured from the Settings page (DB) instead of only via
 * .env — the same "DB value wins over env" precedent as {@see GeminiConfig} and
 * {@see VoiceConfig}.
 *
 * Applied at the POINT OF USE (the send job + the Settings test-send), NOT in a
 * service provider boot(): `php artisan config:cache` boots providers, so an
 * apply() in boot() would bake the operator's SMTP values into the cached config
 * file. Resolving on demand keeps config:cache honest and matches how the sibling
 * config resolvers work.
 */
final class MailConfig
{
    /**
     * The effective default mailer: the Settings-page value wins over
     * config/mail.php (which is env-driven). Falls back to env when unset.
     */
    public static function mailer(): string
    {
        $fromDb = self::db('mail_mailer');

        return $fromDb !== null && $fromDb !== '' ? $fromDb : (string) config('mail.default');
    }

    /**
     * True once the operator has saved a mail transport from the Settings page.
     * When false, apply() leaves the env-based config/mail.php untouched.
     */
    public static function isConfiguredInDb(): bool
    {
        $fromDb = self::db('mail_mailer');

        return $fromDb !== null && $fromDb !== '';
    }

    /**
     * True when the effective transport actually delivers mail. `log`/`array`
     * (and an empty mailer) accept the message and drop it — a silent black hole
     * that makes a campaign report SENT while nothing arrives (audit M11).
     */
    public static function isDelivering(): bool
    {
        return ! in_array(self::mailer(), ['log', 'array', ''], true);
    }

    /**
     * Overlay the DB-stored transport onto the runtime mail config so campaigns
     * (and the Settings test-send) use the operator's SMTP instead of the .env
     * default. No-op when nothing is configured in the DB — env still works —
     * and when the settings table isn't available (pre-migrate / isolated test).
     */
    public static function apply(): void
    {
        try {
            $mailer = Setting::get('mail_mailer');
            if ($mailer === null || $mailer === '') {
                return;
            }

            config(['mail.default' => $mailer]);

            if ($mailer === 'smtp') {
                $encryption = Setting::get('mail_encryption');

                config([
                    'mail.mailers.smtp.host' => Setting::get('mail_host') ?: config('mail.mailers.smtp.host'),
                    'mail.mailers.smtp.port' => (int) (Setting::get('mail_port') ?: 587),
                    'mail.mailers.smtp.username' => Setting::get('mail_username') ?: null,
                    'mail.mailers.smtp.password' => Setting::getEncrypted('mail_password') ?: null,
                    'mail.mailers.smtp.scheme' => self::scheme($encryption),
                ]);

                // An explicit "no encryption" choice must actually disable TLS.
                // Symfony's EsmtpTransport defaults auto_tls=true and will still
                // opportunistically STARTTLS whenever the server advertises it —
                // silently overriding the operator's choice and, on a broken/self-
                // signed cert, failing the ENTIRE campaign (every recipient's
                // EmailLog marked FAILED). Mirror SmtpSender's per-mailbox fix.
                if (in_array($encryption, ['none', '', null], true)) {
                    config(['mail.mailers.smtp.auto_tls' => false]);
                }
            }

            $fromAddress = Setting::get('mail_from_address');
            if ($fromAddress !== null && $fromAddress !== '') {
                config(['mail.from.address' => $fromAddress]);
            }

            $fromName = Setting::get('mail_from_name');
            if ($fromName !== null && $fromName !== '') {
                config(['mail.from.name' => $fromName]);
            }
        } catch (QueryException) {
            // No settings table yet — leave the env-based mail config in place.
        }
    }

    /**
     * Map the operator's encryption choice onto Laravel's SMTP transport scheme:
     * `smtps` = implicit TLS (typically port 465); `smtp` = plain / STARTTLS,
     * which the transport negotiates automatically when the server offers it
     * (typically port 587). Anything that isn't SSL uses `smtp`.
     */
    private static function scheme(?string $encryption): string
    {
        return $encryption === 'ssl' ? 'smtps' : 'smtp';
    }

    private static function db(string $key): ?string
    {
        try {
            return Setting::get($key);
        } catch (QueryException) {
            return null;
        }
    }
}
