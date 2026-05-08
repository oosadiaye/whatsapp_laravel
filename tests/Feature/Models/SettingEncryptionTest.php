<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_encrypted_then_get_encrypted_round_trips_value(): void
    {
        Setting::setEncrypted('africastalking_api_key', 'atsk_test_secret_value_12345');

        $retrieved = Setting::getEncrypted('africastalking_api_key');

        $this->assertSame('atsk_test_secret_value_12345', $retrieved);
    }

    public function test_get_encrypted_returns_default_when_key_missing(): void
    {
        $retrieved = Setting::getEncrypted('not_set_key', 'fallback_default');

        $this->assertSame('fallback_default', $retrieved);
    }

    public function test_get_encrypted_returns_default_when_db_value_corrupt(): void
    {
        // Store unencrypted garbage directly via plain set, simulating manual DB tampering.
        Setting::set('africastalking_api_key', 'this-is-not-valid-encrypted-text');

        $retrieved = Setting::getEncrypted('africastalking_api_key', 'fallback');

        // Decryption fails → method returns the default rather than throwing.
        $this->assertSame('fallback', $retrieved);
    }
}
