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
}
