<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
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

    // ---- Webhook → EmailLog/counter reconciliation --------------------------

    public function test_hard_bounce_flips_the_sent_log_to_failed_and_reconciles_counters(): void
    {
        $campaign = EmailCampaign::factory()->create(['sent_count' => 1, 'failed_count' => 0, 'opened_count' => 0]);
        EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'bounced@example.com',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'Bounced@Example.com',
        ])->assertOk();

        $this->assertSame(EmailLog::STATUS_FAILED, $campaign->logs()->first()->status);
        $campaign->refresh();
        $this->assertSame(0, $campaign->sent_count);    // no longer counted as sent
        $this->assertSame(1, $campaign->failed_count);  // now counted as failed
    }

    public function test_hard_bounce_on_an_opened_log_also_decrements_opened_count(): void
    {
        $campaign = EmailCampaign::factory()->create(['sent_count' => 1, 'failed_count' => 0, 'opened_count' => 1]);
        EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'opened-then-bounced@example.com',
            'status' => EmailLog::STATUS_OPENED,
            'opened_at' => now(),
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'opened-then-bounced@example.com',
        ])->assertOk();

        $campaign->refresh();
        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame(0, $campaign->opened_count);
        $this->assertSame(1, $campaign->failed_count);
    }

    public function test_spam_complaint_marks_the_log_unsubscribed(): void
    {
        $campaign = EmailCampaign::factory()->create(['sent_count' => 1, 'failed_count' => 0]);
        EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'complainer@example.com',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'SpamComplaint',
            'Email' => 'complainer@example.com',
        ])->assertOk();

        $this->assertSame(EmailLog::STATUS_UNSUBSCRIBED, $campaign->logs()->first()->status);
        $campaign->refresh();
        $this->assertSame(0, $campaign->sent_count); // a complaint is not a "sent"
        $this->assertSame(0, $campaign->failed_count);
    }

    public function test_soft_bounce_does_not_reconcile_logs(): void
    {
        $campaign = EmailCampaign::factory()->create(['sent_count' => 1, 'failed_count' => 0]);
        EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'soft@example.com',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'SoftBounce',
            'Email' => 'soft@example.com',
        ])->assertOk();

        // Recoverable — the log stays SENT, counters untouched.
        $this->assertSame(EmailLog::STATUS_SENT, $campaign->logs()->first()->status);
        $campaign->refresh();
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
    }

    public function test_hard_bounce_marks_sequence_recipients_bounced(): void
    {
        $sequence = EmailSequence::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Seq',
            'status' => EmailSequence::STATUS_ACTIVE,
        ]);
        EmailSequenceRecipient::create([
            'email_sequence_id' => $sequence->id,
            'email' => 'seq-bounced@example.com',
            'current_step' => 0,
            'status' => EmailSequence::RECIPIENT_SENT,
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'Email' => 'Seq-Bounced@Example.com',
        ])->assertOk();

        $this->assertSame(
            EmailSequence::RECIPIENT_BOUNCED,
            EmailSequenceRecipient::where('email', 'seq-bounced@example.com')->first()->status,
        );
    }

    public function test_spam_complaint_marks_sequence_recipients_unsubscribed(): void
    {
        $sequence = EmailSequence::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Seq',
            'status' => EmailSequence::STATUS_ACTIVE,
        ]);
        EmailSequenceRecipient::create([
            'email_sequence_id' => $sequence->id,
            'email' => 'seq-complaint@example.com',
            'current_step' => 0,
            'status' => EmailSequence::RECIPIENT_PENDING,
        ]);

        $this->postJson($this->url(), [
            'RecordType' => 'SpamComplaint',
            'Email' => 'seq-complaint@example.com',
        ])->assertOk();

        $this->assertSame(
            EmailSequence::RECIPIENT_UNSUBSCRIBED,
            EmailSequenceRecipient::where('email', 'seq-complaint@example.com')->first()->status,
        );
    }
}
