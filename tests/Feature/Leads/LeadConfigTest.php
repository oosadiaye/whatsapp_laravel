<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Setting;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_is_null_when_nothing_is_set(): void
    {
        config(['services.apollo.key' => null]);

        $this->assertNull(LeadConfig::key('apollo'));
    }

    public function test_a_saved_key_is_stored_encrypted_and_read_back(): void
    {
        LeadConfig::setKey('apollo', 'secret-key-123456');

        $this->assertSame('secret-key-123456', LeadConfig::key('apollo'));
        $this->assertNotSame('secret-key-123456', Setting::get('lead_source_apollo_key'));
    }

    public function test_saved_key_beats_the_environment_default(): void
    {
        config(['services.apollo.key' => 'env-key-0000000000']);
        LeadConfig::setKey('apollo', 'db-key-11111111111');

        $this->assertSame('db-key-11111111111', LeadConfig::key('apollo'));
    }

    public function test_environment_key_is_used_when_nothing_is_saved(): void
    {
        config(['services.apollo.key' => 'env-key-0000000000']);

        $this->assertSame('env-key-0000000000', LeadConfig::key('apollo'));
    }

    public function test_kill_switch_defaults_off_and_toggles(): void
    {
        $this->assertFalse(LeadConfig::killSwitchOn());

        LeadConfig::setKillSwitch(true);
        $this->assertTrue(LeadConfig::killSwitchOn());

        LeadConfig::setKillSwitch(false);
        $this->assertFalse(LeadConfig::killSwitchOn());
    }
}
