<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstanceReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstance(array $attrs): WhatsAppInstance
    {
        return WhatsAppInstance::factory()->create(array_merge([
            'waba_id' => 'w-'.uniqid(),
            'phone_number_id' => 'p-'.uniqid(),
            'access_token' => 'tok',
            'app_secret' => 'sec',
        ], $attrs));
    }

    public function test_is_ready_needs_send_creds_but_not_app_secret(): void
    {
        // Sending only needs the access token — app_secret is a webhook concern.
        $this->assertTrue($this->makeInstance(['app_secret' => null])->isReady());
        $this->assertFalse($this->makeInstance(['access_token' => null])->isReady());
    }

    public function test_is_webhook_ready_also_needs_app_secret(): void
    {
        $this->assertTrue($this->makeInstance([])->isWebhookReady());
        $this->assertFalse($this->makeInstance(['app_secret' => null])->isWebhookReady());
    }

    public function test_settings_page_warns_when_app_secret_is_missing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->makeInstance(['app_secret' => null, 'is_default' => true]);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('App Secret missing');
    }
}
