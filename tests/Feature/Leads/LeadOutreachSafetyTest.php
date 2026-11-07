<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Events\Leads\LeadReplyAlert;
use App\Jobs\HandleLeadReplyJob;
use App\Jobs\SendUserEmail;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Models\LeadReply;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Leads\Outreach\AutonomyPolicy;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Services\Leads\Outreach\ReplyDetector;
use App\Services\Leads\Outreach\ReplyHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ScriptedAiClient;
use Tests\TestCase;

/** Regression tests for the problems found reviewing the outreach code: loops, stale drafts, stuck states, races. */
final class LeadOutreachSafetyTest extends TestCase
{
    use RefreshDatabase;

    private LeadProfile $profile;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
        Queue::fake();
        Mail::fake();
        Event::fake([LeadReplyAlert::class]);

        $owner = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $owner->id]);
        $this->profile = LeadProfile::factory()->active()->create([
            'autonomy' => AutonomyPolicy::FULL_AUTO, 'sender_email_account_id' => $account->id, 'created_by' => $owner->id,
        ]);
        $this->lead = Lead::factory()->create([
            'lead_profile_id' => $this->profile->id, 'email' => 'ada@acme.test', 'name' => 'Ada', 'status' => Lead::STATUS_CONTACTED,
            'owner_user_id' => $owner->id, 'outreach_started_at' => now()->subDay(),
        ]);
        LeadMessage::create(['lead_id' => $this->lead->id, 'kind' => 'first', 'status' => 'sent', 'subject' => 'Invoicing', 'body' => 'x', 'sent_at' => now()->subDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ai(array $replies): void
    {
        $this->app->instance(AiClient::class, new ScriptedAiClient($replies));
    }

    private function verdict(string $class, bool $sensitive = false, int $confidence = 95): string
    {
        return json_encode(['class' => $class, 'confidence' => $confidence, 'sensitive' => $sensitive, 'summary' => 'They wrote.']);
    }

    private function answer(): string
    {
        return '{"subject": "x", "body": "Hi Ada, happy to help with that. Which day suits you for a short call?"}';
    }

    private function reply(string $text = 'Interested, tell me more.'): LeadReply
    {
        $message = EmailMessage::factory()->create(['from_email' => 'ada@acme.test', 'body_text' => $text, 'body_html' => null]);

        return app(ReplyDetector::class)->detect($message);
    }

    public function test_a_message_stuck_in_sending_is_marked_failed_for_a_person_not_resent(): void
    {
        $stuck = LeadMessage::create(['lead_id' => $this->lead->id, 'kind' => 'followup', 'status' => 'sending', 'subject' => 's', 'body' => 'b']);
        LeadMessage::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

        app(LeadMessageSender::class)->sweep();

        $this->assertSame('failed', $stuck->fresh()->status);
        Queue::assertNotPushed(SendUserEmail::class);
    }

    public function test_an_answer_draft_is_not_sent_once_the_lead_is_lost(): void
    {
        $this->lead->update(['status' => Lead::STATUS_LOST]);
        $draft = LeadMessage::create(['lead_id' => $this->lead->id, 'kind' => 'reply', 'status' => 'approved', 'subject' => 'Re: x', 'body' => 'Great, let us book a demo.']);

        $this->assertSame('discarded', app(LeadMessageSender::class)->send($draft));
        Queue::assertNothingPushed();
    }

    public function test_a_no_discards_the_answer_still_waiting_from_an_earlier_reply(): void
    {
        $stale = LeadMessage::create(['lead_id' => $this->lead->id, 'kind' => 'reply', 'status' => 'pending_approval', 'subject' => 'Re: x', 'body' => 'Great, let us book a demo.']);
        $this->ai([$this->verdict('not_interested')]);

        app(ReplyHandler::class)->handle($this->reply('No thanks, not interested.'));

        $this->assertSame('discarded', $stale->fresh()->status);
        $this->assertSame(Lead::STATUS_LOST, $this->lead->fresh()->status);
    }

    public function test_a_late_classification_cannot_undo_an_unsubscribe(): void
    {
        $reply = $this->reply('Away until Monday.');
        $this->lead->update(['status' => Lead::STATUS_UNSUBSCRIBED]);
        $this->ai([$this->verdict('out_of_office')]);

        app(ReplyHandler::class)->handle($reply);

        $lead = $this->lead->fresh();
        $this->assertSame(Lead::STATUS_UNSUBSCRIBED, $lead->status);
        $this->assertNull($lead->next_followup_at);
    }

    public function test_an_auto_responder_loop_is_cut_off_after_three_answers_in_a_day(): void
    {
        $this->ai(array_merge(...array_fill(0, 4, [$this->verdict('question'), $this->answer()])));

        $results = [];
        foreach (range(1, 4) as $i) {
            $this->lead->update(['status' => Lead::STATUS_CONTACTED]);
            $results[] = app(ReplyHandler::class)->handle($this->reply("Which product do you mean? ({$i})"));
        }

        $this->assertSame(['handled', 'handled', 'handled', 'escalated'], $results);
        $this->assertSame(3, LeadMessage::where('kind', 'reply')->count());
    }

    public function test_obvious_legal_words_escalate_even_if_the_model_says_all_clear(): void
    {
        $this->ai([$this->verdict('interested', sensitive: false, confidence: 99)]);

        $result = app(ReplyHandler::class)->handle($this->reply('Interested. Also my lawyer will be in touch.'));

        $this->assertSame('escalated', $result);
        $this->assertSame(0, LeadMessage::where('kind', 'reply')->count());
    }

    public function test_handling_a_reply_twice_does_not_draft_two_answers(): void
    {
        $reply = $this->reply();
        $this->ai([$this->verdict('question'), $this->answer(), $this->verdict('question'), $this->answer()]);

        app(ReplyHandler::class)->handle($reply);
        LeadReply::whereKey($reply->id)->update(['status' => LeadReply::STATUS_NEW]);
        app(ReplyHandler::class)->handle($reply->fresh());

        $this->assertSame(1, LeadMessage::where('kind', 'reply')->count());
    }

    public function test_the_scheduled_command_picks_up_a_reply_nobody_finished(): void
    {
        $reply = $this->reply();
        Queue::fake();
        LeadReply::whereKey($reply->id)->update(['created_at' => now()->subMinutes(30)]);

        $this->artisan('leads:outreach')->assertSuccessful();

        Queue::assertPushed(HandleLeadReplyJob::class, fn (HandleLeadReplyJob $job) => $job->replyId === $reply->id);
    }

    public function test_a_reply_the_classifier_gave_up_on_goes_to_a_person(): void
    {
        $reply = $this->reply();
        LeadReply::whereKey($reply->id)->update(['created_at' => now()->subMinutes(30), 'classify_attempts' => 3]);

        $this->artisan('leads:outreach')->assertSuccessful();

        $this->assertSame(LeadReply::STATUS_NEEDS_HUMAN, $reply->fresh()->status);
    }

    public function test_approving_a_draft_that_was_just_discarded_does_nothing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $draft = LeadMessage::create(['lead_id' => $this->lead->id, 'kind' => 'followup', 'status' => 'pending_approval', 'subject' => 's', 'body' => 'A long enough body.']);

        $this->actingAs($admin)->post(route('lead-approvals.discard', $draft));
        $this->actingAs($admin)->post(route('lead-approvals.approve', $draft))->assertSessionHas('warning');

        $this->assertSame('discarded', $draft->fresh()->status);
        Queue::assertNotPushed(SendUserEmail::class);
    }
}
