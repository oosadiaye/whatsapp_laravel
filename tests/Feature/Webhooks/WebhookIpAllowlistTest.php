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

    public function test_allowlist_disabled_lets_the_request_through(): void
    {
        config(['voice.webhook_ip_allowlist' => []]);

        // The controller may 401/whatever on an empty body — the point is the
        // middleware did NOT block it (no 403).
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
