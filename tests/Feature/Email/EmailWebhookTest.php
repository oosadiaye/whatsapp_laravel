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

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.email_webhooks.secret' => 'test-secret']);
    }

    private function url(string $provider = 'postmark', string $secret = 'test-secret'): string
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
        $this->postJson($this->url('mailchimp', 'test-secret'), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'x@example.com',
        ])->assertNotFound();
    }
}
