<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The app is installable: manifest + icons + service worker exist and every layout advertises them. */
final class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_valid_and_its_icons_exist(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_the_service_worker_never_caches_pages_or_data(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringNotContainsString('cache.put', $sw);
    }

    public function test_guest_and_app_pages_link_the_manifest_and_carry_the_install_modal(): void
    {
        $guest = $this->get('/login')->assertOk();
        $guest->assertSee('rel="manifest"', false)->assertSee('bq-install-title', false);

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get('/dashboard')->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee('bq-install-title', false)->assertSee('apple-touch-icon', false);
    }
}
