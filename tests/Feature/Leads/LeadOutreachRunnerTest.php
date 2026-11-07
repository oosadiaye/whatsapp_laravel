<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\SendUserEmail;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Services\Ai\AiClient;
use App\Services\Leads\Outreach\AutonomyPolicy;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Services\Leads\Outreach\LeadOutreachRunner;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use App\Events\Leads\LeadReplyAlert;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

final class LeadOutreachRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = '{"subject": "Invoicing for Acme", "body": "Hi Ada, we make invoicing software for Nigerian SMEs. Worth a quick chat?"}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
        Queue::fake();
        $this->app->instance(AiClient::class, new FakeAiClient(self::DRAFT));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(string $autonomy, array $o = []): LeadProfile
    {
        $account = EmailAccount::factory()->create();

        return LeadProfile::factory()->active()->create(array_merge([
            'autonomy' => $autonomy, 'sender_email_account_id' => $account->id, 'followup_count' => 2, 'followup_delay_days' => 3,
        ], $o));
    }

    private function lead(LeadProfile $profile, array $o = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'lead_profile_id' => $profile->id, 'email' => fake()->unique()->safeEmail(), 'email_status' => 'verified',
            'status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_HOT, 'score' => 90,
        ], $o));
    }

    private function runner(): LeadOutreachRunner
    {
        return app(LeadOutreachRunner::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function firstEmailByLevel(): array
    {
        return [
            'manual waits for a person' => [AutonomyPolicy::APPROVE_ALL, LeadMessage::STATUS_PENDING],
            'semi sends by itself' => [AutonomyPolicy::AUTO_FIRST, LeadMessage::STATUS_APPROVED],
            'full sends by itself' => [AutonomyPolicy::FULL_AUTO, LeadMessage::STATUS_APPROVED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('firstEmailByLevel')]
    public function test_the_autonomy_level_decides_whether_a_first_email_waits_for_approval(string $level, string $expected): void
    {
        $profile = $this->profile($level);
        $lead = $this->lead($profile);

        $summary = $this->runner()->run($profile);

        $this->assertSame(1, $summary['drafted']);
        $message = LeadMessage::firstOrFail();
        $this->assertSame($expected, $message->status);
        $this->assertSame('first', $message->kind);
        $this->assertSame($lead->id, $message->lead_id);
        Queue::assertNothingPushed(); // drafting never sends; the sender does
    }

    public function test_the_owner_is_alerted_once_when_a_run_leaves_drafts_waiting_for_approval(): void
    {
        Event::fake([LeadReplyAlert::class]);
        $profile = $this->profile(AutonomyPolicy::APPROVE_ALL);
        $this->lead($profile);
        $this->lead($profile);

        $this->runner()->run($profile);

        Event::assertDispatchedTimes(LeadReplyAlert::class, 1);
        Event::assertDispatched(LeadReplyAlert::class, fn (LeadReplyAlert $e) => $e->kind === 'approval'
            && $e->userId === $profile->senderAccount->user_id
            && str_contains($e->headline, '2 emails are waiting'));
    }

    public function test_no_alert_when_nothing_waits_for_approval(): void
    {
        Event::fake([LeadReplyAlert::class]);
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $this->lead($profile);

        $this->runner()->run($profile);

        Event::assertNotDispatched(LeadReplyAlert::class);
    }

    public function test_an_approved_draft_is_sent_by_the_next_sweep_and_a_waiting_one_is_not(): void
    {
        $auto = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $this->lead($auto);
        $manual = $this->profile(AutonomyPolicy::APPROVE_ALL);
        $this->lead($manual);
        $this->runner()->run($auto);
        $this->runner()->run($manual);

        $sent = app(LeadMessageSender::class)->sweep();

        $this->assertSame(1, $sent);
        Queue::assertPushed(SendUserEmail::class, 1);
        $this->assertSame(1, LeadMessage::where('status', 'pending_approval')->count());
    }

    public function test_the_lead_is_promoted_to_a_contact_once_and_gets_an_owner(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $lead = $this->lead($profile, ['email' => 'ada@acme.test']);

        $this->runner()->run($profile);
        $this->runner()->run($profile);

        $lead->refresh();
        $this->assertNotNull($lead->contact_id);
        $this->assertSame($profile->senderAccount->user_id, $lead->owner_user_id);
        $this->assertSame(1, Contact::where('email', 'ada@acme.test')->count());
        $this->assertSame(1, LeadMessage::count(), 'no second first-email');
    }

    public function test_a_soft_deleted_contact_with_the_same_email_is_revived_not_a_crash(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $contact = Contact::create(['user_id' => $profile->senderAccount->user_id, 'email' => 'ada@acme.test', 'name' => 'Old']);
        $contact->delete();
        $this->lead($profile, ['email' => 'ada@acme.test']);

        $summary = $this->runner()->run($profile);

        $this->assertSame(1, $summary['drafted']);
        $this->assertNull(Contact::withTrashed()->where('email', 'ada@acme.test')->firstOrFail()->deleted_at);
    }

    public function test_hot_leads_are_written_to_before_warm_ones_and_rejected_or_unreachable_leads_never(): void
    {
        $profile = $this->profile(AutonomyPolicy::APPROVE_ALL);
        $warm = $this->lead($profile, ['tier' => Lead::TIER_WARM, 'score' => 60]);
        $hot = $this->lead($profile, ['tier' => Lead::TIER_HOT, 'score' => 95]);
        $this->lead($profile, ['status' => Lead::STATUS_REJECTED, 'tier' => Lead::TIER_REJECTED]);
        $this->lead($profile, ['email' => null]);

        $this->runner()->run($profile);

        $this->assertSame([$hot->id, $warm->id], LeadMessage::orderBy('id')->pluck('lead_id')->all());
    }

    public function test_a_follow_up_is_drafted_after_the_wait_and_not_before(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $lead = $this->lead($profile, ['status' => Lead::STATUS_CONTACTED, 'next_followup_at' => now()->addDay()]);
        LeadMessage::create(['lead_id' => $lead->id, 'kind' => 'first', 'status' => 'sent', 'subject' => 'Invoicing', 'body' => 'x', 'sent_at' => now()]);

        $this->assertSame(0, $this->runner()->run($profile)['drafted']);

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, $this->runner()->run($profile)['drafted']);

        $followUp = LeadMessage::where('kind', 'followup')->firstOrFail();
        $this->assertSame('Re: Invoicing', $followUp->subject);
        $this->assertSame(1, $followUp->step);
        $this->assertSame('approved', $followUp->status);
        $this->assertNull($lead->fresh()->next_followup_at, 'not drafted twice while one is open');
    }

    public function test_the_follow_up_count_is_respected(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST, ['followup_count' => 1]);
        $this->lead($profile, ['status' => Lead::STATUS_CONTACTED, 'next_followup_at' => now()->subHour(), 'followups_sent' => 1]);

        $this->assertSame(0, $this->runner()->run($profile)['drafted']);
    }

    public function test_a_lead_that_replied_gets_no_follow_up(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $this->lead($profile, ['status' => Lead::STATUS_REPLIED, 'next_followup_at' => now()->subHour()]);

        $this->assertSame(0, $this->runner()->run($profile)['drafted']);
    }

    public function test_the_kill_switch_stops_drafting(): void
    {
        LeadConfig::setKillSwitch(true);
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        $this->lead($profile);

        $summary = $this->runner()->run($profile);

        $this->assertSame(0, LeadMessage::count());
        $this->assertStringContainsString('kill switch', $summary['note']);
    }

    public function test_a_profile_without_a_mailbox_explains_what_is_missing(): void
    {
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST, ['sender_email_account_id' => null]);
        $this->lead($profile);

        $summary = $this->runner()->run($profile);

        $this->assertSame(0, LeadMessage::count());
        $this->assertStringContainsString('sender mailbox', $summary['note']);
        $this->assertStringContainsString('sender mailbox', $profile->fresh()->last_outreach_error);
    }

    public function test_an_ai_outage_drafts_nothing_and_stops_after_a_few_failures(): void
    {
        $ai = new FakeAiClient(null);
        $this->app->instance(AiClient::class, $ai);
        $profile = $this->profile(AutonomyPolicy::AUTO_FIRST);
        foreach (range(1, 8) as $i) {
            $this->lead($profile);
        }

        $summary = $this->runner()->run($profile);

        $this->assertSame(0, LeadMessage::count());
        $this->assertCount(3, $ai->calls, 'gave up after three failures in a row');
        $this->assertSame(0, Lead::where('status', Lead::STATUS_REJECTED)->count(), 'the leads are untouched and retried later');
        $this->assertStringContainsString('not answering', $summary['note']);
    }

    public function test_a_full_queue_pauses_drafting(): void
    {
        $profile = $this->profile(AutonomyPolicy::APPROVE_ALL);
        $waiting = $this->lead($profile, ['status' => Lead::STATUS_CONTACTED]);
        foreach (range(1, 50) as $i) {
            LeadMessage::create(['lead_id' => $waiting->id, 'kind' => 'followup', 'status' => 'pending_approval', 'subject' => 's', 'body' => 'b']);
        }
        $this->lead($profile);

        $this->assertSame(0, $this->runner()->run($profile)['drafted']);
    }
}
