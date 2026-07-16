<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the single-instance WhatsApp contract on /settings.
 *
 * After the "unify into Settings" change there is exactly ONE WhatsApp number
 * for the whole app, configured on the Settings page (no separate Instances
 * CRUD). SettingsController::upsertWhatsAppInstance owns the create/update:
 *
 *   - First save creates THE one instance (WhatsAppInstance::primary()).
 *   - Later saves update that SAME row — never a second instance.
 *   - access_token / app_secret are encrypted at rest and follow the same
 *     "leave blank to keep existing" rule as the AT api_key.
 *
 * If anyone re-introduces a multi-instance create path or drops the encryption
 * cast, one of these tests fails loudly.
 */
class SettingsWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** Probe HTTP status the faked Meta endpoint returns (200 = valid creds). */
    private int $metaStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Saving credentials now probes Meta to verify them. One closure fake
        // reads $this->metaStatus at request time, so a test can flip it to
        // exercise the rejection path without fighting Http::fake merge order.
        Http::fake(['graph.facebook.com/*' => function () {
            return $this->metaStatus === 200
                ? Http::response([
                    'display_phone_number' => '+2348000000000',
                    'verified_name' => 'Verified Biz',
                    'quality_rating' => 'GREEN',
                    'messaging_limit_tier' => 'TIER_1K',
                ], 200)
                : Http::response(['error' => ['message' => 'bad token']], $this->metaStatus);
        }]);
    }

    public function test_probe_marks_the_instance_connected_and_fills_metadata(): void
    {
        $this->actingAs($this->makeAdmin())->put(route('settings.update'), [
            'wa_phone_number_id' => '111', 'wa_waba_id' => '222',
            'wa_access_token' => 'tok', 'wa_app_secret' => 'sec',
        ])->assertRedirect();

        $instance = WhatsAppInstance::primary();
        $this->assertSame(WhatsAppInstance::STATUS_CONNECTED, $instance->status);
        $this->assertSame('+2348000000000', $instance->business_phone_number);
        $this->assertSame('Verified Biz', $instance->display_name); // auto-filled (was blank)
    }

    public function test_probe_flags_credentials_invalid_and_warns_on_meta_rejection(): void
    {
        $this->metaStatus = 401; // Meta rejects the credentials

        $this->actingAs($this->makeAdmin())->put(route('settings.update'), [
            'wa_phone_number_id' => '111', 'wa_waba_id' => '222',
            'wa_access_token' => 'bad', 'wa_app_secret' => 'sec',
        ])->assertRedirect()->assertSessionHas('warning');

        $this->assertSame('CREDENTIALS_INVALID', WhatsAppInstance::primary()->status);
    }

    public function test_saving_credentials_creates_the_single_instance(): void
    {
        $admin = $this->makeAdmin();

        $this->assertNull(WhatsAppInstance::primary());

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'wa_display_name' => 'Company Main Line',
                'wa_phone_number_id' => '111222333',
                'wa_waba_id' => '999888777',
                'wa_access_token' => 'EAAG_long_meta_system_user_token_value',
                'wa_app_secret' => 'meta_app_secret_value',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $instance = WhatsAppInstance::primary();

        $this->assertNotNull($instance);
        $this->assertSame(1, WhatsAppInstance::count(), 'exactly one instance after first save');
        $this->assertSame('Company Main Line', $instance->display_name);
        $this->assertSame('111222333', $instance->phone_number_id);
        $this->assertSame('999888777', $instance->waba_id);
        $this->assertTrue($instance->is_default, 'the single instance is the default');
        // A verify token is auto-generated so the operator can paste it into Meta.
        $this->assertNotEmpty($instance->webhook_verify_token);
    }

    public function test_access_token_and_app_secret_are_encrypted_at_rest(): void
    {
        $admin = $this->makeAdmin();
        $token = 'EAAG_super_sensitive_token_do_not_leak';
        $secret = 'app_secret_super_sensitive';

        $this->actingAs($admin)
            ->put(route('settings.update'), [
                'wa_phone_number_id' => '111222333',
                'wa_waba_id' => '999888777',
                'wa_access_token' => $token,
                'wa_app_secret' => $secret,
            ])
            ->assertRedirect();

        $instance = WhatsAppInstance::primary();

        // Raw DB columns must be ciphertext, not the plaintext values.
        $this->assertNotSame($token, $instance->getRawOriginal('access_token'));
        $this->assertNotSame($secret, $instance->getRawOriginal('app_secret'));

        // The model accessors decrypt back to the originals.
        $this->assertSame($token, $instance->access_token);
        $this->assertSame($secret, $instance->app_secret);

        // Standard Laravel encryption (not a custom obfuscation).
        $this->assertSame($token, Crypt::decryptString($instance->getRawOriginal('access_token')));
    }

    public function test_blank_secrets_do_not_overwrite_existing_values(): void
    {
        $admin = $this->makeAdmin();

        // First save: real credentials.
        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_display_name' => 'Original Name',
            'wa_phone_number_id' => '111222333',
            'wa_waba_id' => '999888777',
            'wa_access_token' => 'original_token_value_kept',
            'wa_app_secret' => 'original_secret_kept',
        ])->assertRedirect();

        // Second save: change the display name, leave both secret fields blank
        // (the form's "leave blank to keep existing" UX).
        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_display_name' => 'Renamed Line',
            'wa_access_token' => '',
            'wa_app_secret' => '',
        ])->assertRedirect();

        $instance = WhatsAppInstance::primary();

        $this->assertSame(1, WhatsAppInstance::count(), 'still one instance — updated in place');
        $this->assertSame('Renamed Line', $instance->display_name);
        // Secrets survived the blank submission.
        $this->assertSame('original_token_value_kept', $instance->access_token);
        $this->assertSame('original_secret_kept', $instance->app_secret);
    }

    public function test_second_save_updates_the_same_instance(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_phone_number_id' => '111',
            'wa_waba_id' => '222',
            'wa_access_token' => 'tok',
            'wa_app_secret' => 'sec',
        ])->assertRedirect();

        $firstId = WhatsAppInstance::primary()->id;

        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_phone_number_id' => '444',
        ])->assertRedirect();

        $this->assertSame(1, WhatsAppInstance::count());
        $this->assertSame($firstId, WhatsAppInstance::primary()->id, 'same row, not a new instance');
        $this->assertSame('444', WhatsAppInstance::primary()->phone_number_id);
    }

    public function test_settings_page_shows_webhook_url_once_configured(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_phone_number_id' => '111222333',
            'wa_waba_id' => '999888777',
            'wa_access_token' => 'tok_value_here',
            'wa_app_secret' => 'sec_value_here',
        ])->assertRedirect();

        $instance = WhatsAppInstance::primary();

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('webhook.cloud.handle', $instance));
    }

    public function test_settings_index_does_not_leak_wa_token_ciphertext(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('settings.update'), [
            'wa_phone_number_id' => '111222333',
            'wa_waba_id' => '999888777',
            'wa_access_token' => 'tok_secret_never_in_html',
            'wa_app_secret' => 'sec_secret_never_in_html',
        ])->assertRedirect();

        $instance = WhatsAppInstance::primary();
        $tokenCipher = $instance->getRawOriginal('access_token');
        $secretCipher = $instance->getRawOriginal('app_secret');

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $response->assertDontSee('tok_secret_never_in_html', false);
        $response->assertDontSee('sec_secret_never_in_html', false);
        $response->assertDontSee($tokenCipher, false);
        $response->assertDontSee($secretCipher, false);
    }

    public function test_saving_only_non_whatsapp_settings_does_not_create_instance(): void
    {
        $admin = $this->makeAdmin();

        // A user updating only AT/sending defaults must not spawn an empty
        // WhatsApp instance.
        $this->actingAs($admin)->put(route('settings.update'), [
            'africastalking_username' => 'sandbox',
        ])->assertRedirect();

        $this->assertNull(WhatsAppInstance::primary());
        $this->assertSame(0, WhatsAppInstance::count());
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
