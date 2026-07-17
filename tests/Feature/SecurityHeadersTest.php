<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for audit M7: baseline security headers on web responses.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'microphone=(self), camera=(), geolocation=()');
    }

    public function test_content_security_policy_locks_down_script_and_framing(): void
    {
        // CSP is applied outside the local env; the test suite runs as 'testing'.
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP header must be set');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        // No third-party script origins — chart.js/AT SDK are self-hosted (M8).
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
        $this->assertStringNotContainsString('unpkg.com', $csp);
    }

    public function test_script_src_is_nonce_based_not_unsafe_inline(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        // Isolate the script-src directive from the rest of the policy.
        preg_match('/script-src[^;]*/', $csp, $m);
        $scriptSrc = $m[0] ?? '';

        $this->assertStringContainsString("'nonce-", $scriptSrc, 'script-src must be nonce-based');
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc, "script-src must not fall back to 'unsafe-inline'");
        // 'unsafe-eval' is a documented residual (Alpine's expression evaluation).
        $this->assertStringContainsString("'unsafe-eval'", $scriptSrc);

        // style-src MUST keep 'unsafe-inline' — inline style="" attributes can't
        // be nonced, and removing it would break the UI.
        preg_match('/style-src[^;]*/', $csp, $sm);
        $this->assertStringContainsString("'unsafe-inline'", $sm[0] ?? '');
    }

    public function test_rendered_inline_scripts_carry_the_header_nonce(): void
    {
        // The per-request nonce in the CSP header must be the same one @vite,
        // Livewire, and our inline <script> tags emit — otherwise the browser
        // blocks them. Render a full app-layout page and prove they match.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", (string) $csp, $m);
        $nonce = $m[1] ?? null;
        $this->assertNotEmpty($nonce, 'CSP must carry a nonce');

        // At least one inline script (Livewire's config script + the dashboard
        // chart script) must carry that exact nonce.
        $this->assertStringContainsString('nonce="'.$nonce.'"', $response->getContent());
    }
}
