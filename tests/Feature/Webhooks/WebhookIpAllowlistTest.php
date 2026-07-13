<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The optional source-IP allowlist on the provider webhook endpoints. Empty =
 * accept from anywhere (default); set = reject requests from other IPs before
 * they ever reach the controller.
 */
class WebhookIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private function postWebhookFrom(string $ip)
    {
        return $this->call(
            'POST',
            route('webhook.africastalking.voice'),
            [], [], [],
            ['REMOTE_ADDR' => $ip],
        );
    }

    public function test_allowlist_middleware_passes_when_disabled(): void
    {
        config(['voice.webhook_ip_allowlist' => []]);

        // The point here is the IP middleware did NOT block (no 403). The
        // controller separately fails closed on missing auth — see below.
        $this->assertNotSame(403, $this->postWebhookFrom('198.51.100.7')->status());
    }

    public function test_webhook_fails_closed_when_no_auth_gate_is_configured(): void
    {
        // Neither a secret nor an IP allowlist → the AT voice webhook must reject
        // (an open, unauthenticated call webhook is a spam/cost-manipulation risk).
        config(['voice.at_webhook_secret' => '', 'voice.webhook_ip_allowlist' => []]);

        $this->postWebhookFrom('198.51.100.7')->assertStatus(401);
    }

    public function test_ip_allowlist_alone_authenticates_when_no_secret(): void
    {
        // With an allowlist enforced, an allowlisted caller is accepted even
        // without a secret (the middleware is the auth gate).
        config(['voice.at_webhook_secret' => '', 'voice.webhook_ip_allowlist' => ['198.51.100.0/24']]);

        $this->assertNotSame(401, $this->postWebhookFrom('198.51.100.7')->status());
        $this->assertNotSame(403, $this->postWebhookFrom('198.51.100.7')->status());
    }

    public function test_request_from_a_non_allowlisted_ip_is_forbidden(): void
    {
        config(['voice.webhook_ip_allowlist' => ['203.0.113.9']]);

        $this->postWebhookFrom('198.51.100.7')->assertForbidden();
    }

    public function test_request_from_an_allowlisted_ip_passes_the_gate(): void
    {
        config(['voice.webhook_ip_allowlist' => ['198.51.100.0/24']]); // CIDR

        $this->assertNotSame(403, $this->postWebhookFrom('198.51.100.7')->status());
    }
}
