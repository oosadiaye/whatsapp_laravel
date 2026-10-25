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

    public function test_the_test_ai_form_is_not_nested_inside_the_settings_form(): void
    {
        // A <form> inside a <form> is discarded by browsers, which turns the "Test AI
        // connection" button into a submit button for the OUTER settings form (it would
        // save settings instead of testing). The test form must stand alone and the
        // button must point at it with the HTML5 form= attribute.
        $html = $this->actingAs($this->admin())->get(route('settings.index'))->assertOk()->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//form[@id="settings-test-ai"]')->length, 'the test form exists once');
        $this->assertSame(0, $xpath->query('//form[@id="settings-test-ai"]/ancestor::form')->length, 'and is not inside another form');
        $this->assertGreaterThan(0, $xpath->query('//button[@form="settings-test-ai"]')->length, 'the button targets it via form=');
    }

    public function test_the_ai_key_visibility_toggles_have_their_alpine_state(): void
    {
        // Both key fields have a show/hide eye button bound to its own flag. A missing flag
        // throws "showGemini is not defined" in the browser and breaks the toggle.
        $html = $this->actingAs($this->admin())->get(route('settings.index'))->assertOk()->getContent();

        preg_match('/x-data="(\{[^"]*provider:[^"]*\})"/', $html, $m);
        $this->assertNotEmpty($m, 'the AI card declares its Alpine state');
        $this->assertStringContainsString('showGemini', $m[1]);
        $this->assertStringContainsString('showOpenRouter', $m[1]);
    }

    public function test_the_settings_page_has_no_truncated_svg_icon_paths(): void
    {
        // The AI card's eye icons once lost the "12 4.5" / "12 19.5" coordinates from their path
        // data, which draws a distorted icon and logs "<path> attribute d: Expected number".
        $html = $this->actingAs($this->admin())->get(route('settings.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('7.36 4.5c4.638', $html);
        $this->assertStringNotContainsString('16.64 19.5c-4.638', $html);
        $this->assertStringNotContainsString('7.244 19.5c.993', $html);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $u->assignRole(User::ROLE_ADMIN);

        return $u;
    }
}
