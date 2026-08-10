<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\GeminiConfig;
use App\Support\MailConfig;
use App\Support\VoiceConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pins the contract for /settings — specifically Africa's Talking voice-provider
 * credentials, which are the ONLY encrypted setting today and the most common
 * source of "I saved it but the call still fails" reports.
 *
 * The flow exercised here is the whole loop:
 *   form submit (PUT /settings)
 *     → SettingsController::update validates + dispatches set/setEncrypted
 *       → Setting model writes encrypted ciphertext for api_key, plain for the rest
 *         → AfricasTalkingVoiceService reads via getEncrypted/get at call time
 *           → matches what the user typed into the form
 *
 * If any link in that chain regresses (e.g. someone refactors the controller
 * to use Setting::set() for the api_key, or the view starts pre-filling the
 * encrypted bytes), one of these tests will fail loudly.
 */
class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_view_settings_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            // Apostrophe gets HTML-escaped to &#039; in the rendered output,
            // so match a stable substring that survives encoding.
            ->assertSee('Voice Provider');
    }

    public function test_user_without_settings_view_permission_gets_403(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        // No role assigned — settings.view is admin/super_admin only.

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_api_key_is_encrypted_at_rest_not_stored_as_plaintext(): void
    {
        $admin = $this->makeAdmin();
        $secret = 'atsk_live_super_sensitive_value_xyz_98765';

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_username' => 'sandbox',
                'africastalking_api_key' => $secret,
                'africastalking_virtual_number' => '+2348000000001',
                'africastalking_rate_per_minute_kobo' => 600,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // The raw column MUST NOT contain the plaintext.
        $rawCipher = Setting::where('key', 'africastalking_api_key')->value('value');
        $this->assertNotSame($secret, $rawCipher, 'API key must not be stored as plaintext.');
        $this->assertNotEmpty($rawCipher);

        // But Setting::getEncrypted MUST return the original plaintext.
        $this->assertSame($secret, Setting::getEncrypted('africastalking_api_key'));

        // And manually decrypting via Crypt should also work — proves it's
        // standard Laravel encryption, not a custom obfuscation that breaks
        // Setting::getEncrypted in any edge case.
        $this->assertSame($secret, Crypt::decryptString($rawCipher));
    }

    public function test_username_and_virtual_number_are_stored_as_plain_strings(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_username' => 'production_acct',
                'africastalking_virtual_number' => '+2348100000099',
                'africastalking_api_key' => 'placeholder_dummy_key_value',
            ])
            ->assertRedirect();

        $this->assertSame('production_acct', Setting::get('africastalking_username'));
        $this->assertSame('+2348100000099', Setting::get('africastalking_virtual_number'));
    }

    public function test_blank_api_key_field_does_not_overwrite_existing_value(): void
    {
        $admin = $this->makeAdmin();

        // First save: real key.
        Setting::setEncrypted('africastalking_api_key', 'atsk_original_key_value');

        // Second save: user updates other fields, leaves API-key field blank
        // (the form's "leave blank to keep existing" UX).
        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_username' => 'new_username',
                'africastalking_api_key' => '',  // blank
                'africastalking_virtual_number' => '+2348100000022',
            ])
            ->assertRedirect();

        // The encrypted key MUST still be the original — not blanked out.
        $this->assertSame('atsk_original_key_value', Setting::getEncrypted('africastalking_api_key'));
        // ...while other fields did update.
        $this->assertSame('new_username', Setting::get('africastalking_username'));
    }

    public function test_invalid_virtual_number_rejected_by_validator(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_virtual_number' => '08012345678',  // missing leading '+'
            ])
            ->assertSessionHasErrors('africastalking_virtual_number');

        $this->assertNull(Setting::get('africastalking_virtual_number'));
    }

    public function test_short_api_key_rejected_by_validator(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_api_key' => 'short',  // < 10 chars
            ])
            ->assertSessionHasErrors('africastalking_api_key');

        $this->assertNull(Setting::get('africastalking_api_key'));
    }

    public function test_index_does_not_leak_api_key_ciphertext_to_view(): void
    {
        $admin = $this->makeAdmin();
        $secret = 'atsk_secret_must_not_appear_in_html';
        Setting::setEncrypted('africastalking_api_key', $secret);

        $rawCipher = Setting::where('key', 'africastalking_api_key')->value('value');

        $response = $this->actingAs($admin)->get(route('settings.index'));

        // The ciphertext (which is what's stored in the DB) MUST NOT appear
        // anywhere in the rendered HTML. The view should see a truthy sentinel
        // for the "•••" placeholder, not the actual encrypted bytes.
        $response->assertOk();
        $response->assertDontSee($rawCipher, false);
        $response->assertDontSee($secret, false);
    }

    public function test_saved_credentials_are_what_the_voice_service_reads(): void
    {
        // The end-to-end "saved accurately AND used" assertion: round-trip
        // form submission to the same accessors AfricasTalkingVoiceService uses.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'africastalking_username' => 'acct_used_by_service',
                'africastalking_api_key' => 'atsk_key_used_by_service_xxxx',
                'africastalking_virtual_number' => '+2348111000222',
                'africastalking_rate_per_minute_kobo' => 800,
            ])
            ->assertRedirect();

        // These three accessors are EXACTLY what AfricasTalkingVoiceService
        // and AfricasTalkingWebhookController call at runtime — so what the
        // service actually sees must equal what the form just saved.
        $this->assertSame('acct_used_by_service', Setting::get('africastalking_username'));
        $this->assertSame('atsk_key_used_by_service_xxxx', Setting::getEncrypted('africastalking_api_key'));
        $this->assertSame('+2348111000222', Setting::get('africastalking_virtual_number'));
        $this->assertSame('800', Setting::get('africastalking_rate_per_minute_kobo'));
    }

    public function test_settings_page_shows_the_gemini_api_key_field(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Call transcription')
            ->assertSee('gemini_api_key', false);
    }

    public function test_gemini_api_key_is_saved_encrypted_and_resolves_via_config(): void
    {
        $admin = $this->makeAdmin();
        $secret = 'AIzaSy-super-secret-gemini-key-000111';

        $this->actingAs($admin)
            ->put(route('settings.update'), ['gemini_api_key' => $secret])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Stored as ciphertext, not plaintext.
        $raw = Setting::where('key', 'gemini_api_key')->value('value');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));

        // The resolver (used across the call-AI pipeline) returns it.
        $this->assertSame($secret, GeminiConfig::key());
    }

    public function test_blank_gemini_key_does_not_overwrite_the_existing_one(): void
    {
        $admin = $this->makeAdmin();
        Setting::setEncrypted('gemini_api_key', 'existing-gemini-key-123456');

        $this->actingAs($admin)
            ->put(route('settings.update'), ['gemini_api_key' => ''])
            ->assertRedirect();

        $this->assertSame('existing-gemini-key-123456', GeminiConfig::key());
    }

    public function test_gemini_setting_takes_precedence_over_the_env_key(): void
    {
        config(['services.gemini.key' => 'env-fallback-key-abcdef']);

        // No DB setting → env fallback.
        $this->assertSame('env-fallback-key-abcdef', GeminiConfig::key());

        // DB setting wins.
        Setting::setEncrypted('gemini_api_key', 'db-key-wins-999888');
        $this->assertSame('db-key-wins-999888', GeminiConfig::key());
    }

    public function test_recording_cannot_be_enabled_without_a_consent_notice(): void
    {
        $this->actingAs($this->makeAdmin())
            ->put(route('settings.update'), [
                'voice_recording_enabled' => '1',
                'voice_recording_consent_notice' => '',
            ])
            ->assertSessionHasErrors('voice_recording_consent_notice');

        $this->assertFalse(VoiceConfig::recordingEnabled());
    }

    public function test_recording_can_be_enabled_with_a_consent_notice(): void
    {
        $notice = 'This call may be recorded for quality and training.';

        $this->actingAs($this->makeAdmin())
            ->put(route('settings.update'), [
                'voice_recording_enabled' => '1',
                'voice_recording_consent_notice' => $notice,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(VoiceConfig::recordingEnabled());
        $this->assertSame($notice, VoiceConfig::recordingConsentNotice());
    }

    public function test_recording_can_be_disabled(): void
    {
        Setting::set('voice_recording_enabled', '1');

        $this->actingAs($this->makeAdmin())
            ->put(route('settings.update'), [
                // checkbox absent = unchecked
                'voice_recording_consent_notice' => 'still on file',
            ])
            ->assertRedirect();

        $this->assertFalse(VoiceConfig::recordingEnabled());
    }

    public function test_recording_setting_takes_precedence_over_env(): void
    {
        config(['voice.call_recording_enabled' => false]);
        $this->assertFalse(VoiceConfig::recordingEnabled());

        Setting::set('voice_recording_enabled', '1');
        $this->assertTrue(VoiceConfig::recordingEnabled());
    }

    public function test_recording_retention_days_is_saved_and_resolves(): void
    {
        $this->actingAs($this->makeAdmin())
            ->put(route('settings.update'), ['voice_recording_retention_days' => '30'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(30, VoiceConfig::recordingRetentionDays());
    }

    public function test_retention_days_setting_takes_precedence_over_env(): void
    {
        config(['voice.recording_retention_days' => 90]);
        $this->assertSame(90, VoiceConfig::recordingRetentionDays());

        Setting::set('voice_recording_retention_days', '14');
        $this->assertSame(14, VoiceConfig::recordingRetentionDays());
    }

    public function test_settings_page_shows_the_email_smtp_card(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Email delivery')
            ->assertSee('mail_host', false);
    }

    public function test_smtp_settings_are_saved_with_the_password_encrypted(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.mailhost.test',
                'mail_port' => 587,
                'mail_encryption' => 'tls',
                'mail_username' => 'postmaster@blastiq.test',
                'mail_password' => 'super-secret-smtp-pass',
                'mail_from_address' => 'hello@blastiq.test',
                'mail_from_name' => 'BlastIQ Mail',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Non-secret fields stored plain.
        $this->assertSame('smtp', Setting::get('mail_mailer'));
        $this->assertSame('smtp.mailhost.test', Setting::get('mail_host'));
        $this->assertSame('587', Setting::get('mail_port'));
        $this->assertSame('hello@blastiq.test', Setting::get('mail_from_address'));

        // Password stored encrypted, not plaintext.
        $raw = Setting::where('key', 'mail_password')->value('value');
        $this->assertNotSame('super-secret-smtp-pass', $raw);
        $this->assertSame('super-secret-smtp-pass', Crypt::decryptString($raw));

        // The resolver applies them onto the runtime mail config.
        MailConfig::apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.mailhost.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('super-secret-smtp-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('hello@blastiq.test', config('mail.from.address'));
    }

    public function test_blank_smtp_password_does_not_overwrite_the_existing_one(): void
    {
        $admin = $this->makeAdmin();
        Setting::set('mail_mailer', 'smtp');
        Setting::setEncrypted('mail_password', 'original-smtp-pass');

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.mailhost.test',
                'mail_password' => '', // blank → keep existing
            ])
            ->assertRedirect();

        $this->assertSame('original-smtp-pass', Setting::getEncrypted('mail_password'));
        $this->assertSame('smtp.mailhost.test', Setting::get('mail_host'));
    }

    public function test_choosing_env_default_transport_clears_the_db_override(): void
    {
        $admin = $this->makeAdmin();
        Setting::set('mail_mailer', 'smtp');
        $this->assertTrue(MailConfig::isConfiguredInDb());

        // Submitting with the "Use .env default" option (empty mailer) must clear
        // the override — not leave it stuck at smtp via the skip-empty loop.
        $this->actingAs($admin)
            ->put(route('settings.update'), ['mail_mailer' => ''])
            ->assertRedirect();

        $this->assertFalse(MailConfig::isConfiguredInDb());
    }

    public function test_test_email_warns_when_the_transport_does_not_deliver(): void
    {
        $admin = $this->makeAdmin();
        Setting::set('mail_mailer', 'log'); // non-delivering

        $this->actingAs($admin)
            ->post(route('settings.test-email'), ['test_email' => 'ops@blastiq.test'])
            ->assertRedirect()
            ->assertSessionHas('warning');
    }

    public function test_test_email_is_sent_when_the_transport_delivers(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        Setting::set('mail_mailer', 'smtp');
        Setting::set('mail_host', 'smtp.mailhost.test');

        $this->actingAs($admin)
            ->post(route('settings.test-email'), ['test_email' => 'ops@blastiq.test'])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
