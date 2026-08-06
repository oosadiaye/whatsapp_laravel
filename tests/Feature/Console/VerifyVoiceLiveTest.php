<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class VerifyVoiceLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_failure_when_blocking_prerequisites_missing(): void
    {
        // Empty DB → no AT settings; phpunit forces BROADCAST_CONNECTION=null,
        // so the broadcast + secret + credentials hard checks all fail.
        $this->artisan('voice:verify-live', ['--no-call' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('Africa\'s Talking username is set');
    }

    public function test_returns_success_when_prerequisites_met(): void
    {
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');

        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        config([
            'voice.at_webhook_secret' => 'test-secret',
            'broadcasting.default' => 'reverb',
            'queue.default' => 'database',
            'app.url' => 'https://app.example.com',
        ]);

        // --no-call keeps this offline; the capability-token check resolves to
        // the local testing stub. Reverb reachability + APP_URL are WARN-only.
        $this->artisan('voice:verify-live', [
            '--to' => '+2348000000000',
            '--no-call' => true,
        ])->assertExitCode(0);
    }
}
