<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies TrustProxies is configured from config('app.trusted_proxies') in
 * AppServiceProvider::boot (review M1) — the wiring that lets an operator pin
 * trust to their LB CIDR via TRUSTED_PROXIES.
 */
class TrustedProxiesTest extends TestCase
{
    public function test_trusted_proxies_static_is_applied_from_config(): void
    {
        $prop = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
        $prop->setAccessible(true);

        // Test env leaves TRUSTED_PROXIES unset → config default '*'.
        $this->assertSame('*', config('app.trusted_proxies'));
        $this->assertSame('*', $prop->getValue(), 'boot() must apply config to TrustProxies');
    }

    public function test_forwarded_client_ip_is_honored_when_proxies_are_trusted(): void
    {
        // With proxies trusted ('*' by default), X-Forwarded-For is resolved as
        // the real client IP — this is what the webhook IP allowlist + IP-keyed
        // rate limits depend on behind nginx (H5).
        Route::get('/__client_ip', fn () => request()->ip());

        $this->get('/__client_ip', ['X-Forwarded-For' => '203.0.113.42'])
            ->assertOk()
            ->assertSee('203.0.113.42');
    }
}
