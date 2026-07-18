<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provider bounce/complaint ingestion → auto-suppression (EmailWebhookController
 * + the EmailEvents parser layer). Exercises the Postmark reference parser plus
 * the fail-closed URL-secret auth shared by every provider.
 */
class EmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** A realistic (>= 16 char) secret; short secrets are rejected as too weak. */
    private const SECRET = 'webhook-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.email_webhooks.secret' => self::SECRET]);
    }

    private function url(string $provider = 'postmark', ?string $secret = self::SECRET): string
    {
        return "/webhooks/email/{$provider}/{$secret}";
    }

    public function test_hard_bounce_suppresses_the_address(): void
    {
        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'Bounced@Example.com', // mixed case — must normalise
        ])->assertOk();

        $this->assertTrue(EmailSuppression::isSuppressed('bounced@example.com'));
        $this->assertSame(
            EmailSuppression::REASON_BOUNCE,
            EmailSuppression::where('email', 'bounced@example.com')->first()->reason,
        );
    }

    public function test_spam_complaint_suppresses_the_address(): void
    {
        $this->postJson($this->url(), [
            'RecordType' => 'SpamComplaint',
            'Email' => 'complainer@example.com',
        ])->assertOk();

        $this->assertSame(
            EmailSuppression::REASON_COMPLAINT,
            EmailSuppression::where('email', 'complainer@example.com')->first()?->reason,
        );
    }

    public function test_soft_bounce_is_not_suppressed(): void
    {
        // Transient failures recover — suppressing them would drop deliverable
        // contacts.
        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'SoftBounce',
            'Email' => 'soft@example.com',
        ])->assertOk();

        $this->assertFalse(EmailSuppression::isSuppressed('soft@example.com'));
    }

    public function test_a_wrong_secret_is_forbidden_and_suppresses_nothing(): void
    {
        $this->postJson($this->url('postmark', 'wrong-secret'), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'x@example.com',
        ])->assertForbidden();

        $this->assertFalse(EmailSuppression::isSuppressed('x@example.com'));
    }

    public function test_endpoint_is_absent_until_a_secret_is_configured(): void
    {
        config(['services.email_webhooks.secret' => '']);

        $this->postJson($this->url('postmark', 'anything'), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'x@example.com',
        ])->assertNotFound();
    }

    public function test_unknown_provider_is_not_found(): void
    {
        $this->postJson($this->url('mailchimp'), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'x@example.com',
        ])->assertNotFound();
    }

    public function test_a_too_weak_configured_secret_disables_the_endpoint(): void
    {
        config(['services.email_webhooks.secret' => 'short']); // < 16 chars

        $this->postJson($this->url('postmark', 'short'), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'x@example.com',
        ])->assertNotFound();

        $this->assertFalse(EmailSuppression::isSuppressed('x@example.com'));
    }

    public function test_a_malformed_email_in_the_payload_is_not_suppressed(): void
    {
        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'not-an-email',
        ])->assertOk(); // authed + parseable, so 200 — but nothing suppressed

        $this->assertSame(0, EmailSuppression::count());
    }
}
