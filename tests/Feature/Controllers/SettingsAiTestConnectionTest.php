<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Test AI connection" button must never round-trip the saved API key through
 * the browser: the page may not print it, and the server must test the SAVED
 * provider/key, not whatever the request body claims.
 */
class SettingsAiTestConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-or-super-secret-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::flushCache();
        Config::set('services.gemini.key', '');
        Config::set('services.openrouter.key', '');
    }

    public function test_settings_page_never_prints_the_saved_ai_key(): void
    {
        Setting::setEncrypted('ai_provider', 'openrouter');
        Setting::setEncrypted('openrouter_api_key', self::SECRET);

        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee(self::SECRET, false);
    }

    public function test_it_tests_the_saved_key_without_the_request_supplying_one(): void
    {
        Setting::setEncrypted('ai_provider', 'openrouter');
        Setting::setEncrypted('openrouter_api_key', self::SECRET);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'pong']]]])]);

        $this->actingAs($this->admin())
            ->post(route('settings.test-ai'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
    }

    public function test_it_warns_when_no_key_is_saved_even_if_the_request_sends_one(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('settings.test-ai'), ['ai_provider' => 'gemini', 'gemini_api_key' => 'attacker-supplied-key'])
            ->assertSessionHas('warning');

        Http::assertNothingSent();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $u->assignRole(User::ROLE_ADMIN);

        return $u;
    }
}
