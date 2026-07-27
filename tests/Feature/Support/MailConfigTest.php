<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Setting;
use App\Support\MailConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mail-transport resolver: the Settings-page (DB) transport must override
 * the env-based config/mail.php at the point of use, while an unconfigured DB
 * leaves env untouched. Mirrors the GeminiConfig / VoiceConfig precedence.
 */
class MailConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_is_a_noop_when_nothing_is_configured_in_the_db(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.host' => 'env.example.test',
        ]);

        MailConfig::apply();

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('env.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_apply_overrides_config_from_db_smtp_settings(): void
    {
        Setting::set('mail_mailer', 'smtp');
        Setting::set('mail_host', 'smtp.db.test');
        Setting::set('mail_port', '2525');
        Setting::set('mail_username', 'user@db.test');
        Setting::setEncrypted('mail_password', 'db-smtp-secret');
        Setting::set('mail_encryption', 'tls');
        Setting::set('mail_from_address', 'from@db.test');
        Setting::set('mail_from_name', 'DB Sender');

        MailConfig::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.db.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('user@db.test', config('mail.mailers.smtp.username'));
        $this->assertSame('db-smtp-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme')); // tls → smtp (STARTTLS)
        $this->assertSame('from@db.test', config('mail.from.address'));
        $this->assertSame('DB Sender', config('mail.from.name'));
    }

    public function test_ssl_encryption_maps_to_the_smtps_scheme(): void
    {
        Setting::set('mail_mailer', 'smtp');
        Setting::set('mail_host', 'smtp.db.test');
        Setting::set('mail_encryption', 'ssl');

        MailConfig::apply();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_mailer_prefers_the_db_value_over_env(): void
    {
        config(['mail.default' => 'log']);
        $this->assertSame('log', MailConfig::mailer());

        Setting::set('mail_mailer', 'smtp');
        $this->assertSame('smtp', MailConfig::mailer());
    }

    public function test_is_delivering_is_false_for_log_and_true_for_smtp(): void
    {
        Setting::set('mail_mailer', 'log');
        $this->assertFalse(MailConfig::isDelivering());

        Setting::set('mail_mailer', 'smtp');
        $this->assertTrue(MailConfig::isDelivering());
    }

    public function test_empty_db_mailer_is_treated_as_not_configured(): void
    {
        Setting::set('mail_mailer', '');
        $this->assertFalse(MailConfig::isConfiguredInDb());

        config(['mail.default' => 'log']);
        MailConfig::apply();
        $this->assertSame('log', config('mail.default'));
    }
}
