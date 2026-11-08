<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Events\Leads\LeadReplyAlert;
use App\Models\Board;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\LeadReply;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Leads\Outreach\AutonomyPolicy;
use App\Services\Leads\Outreach\LeadFollowUpTask;
use App\Services\Leads\Outreach\ReplyHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ScriptedAiClient;
use Tests\TestCase;

/** A person who says yes gets a card on the board, so the follow-up call cannot be forgotten. */
final class LeadInterestedTaskTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = '{"subject": "ignored", "body": "Hi Ada, happy to help. Which day this week suits you for a short call?"}';

    private User $owner;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
        Queue::fake();
        Mail::fake();
        Event::fake([LeadReplyAlert::class]);

        $this->owner = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $this->owner->id]);
        $profile = LeadProfile::factory()->active()->create([
            'autonomy' => AutonomyPolicy::APPROVE_ALL, 'sender_email_account_id' => $account->id, 'created_by' => $this->owner->id,
        ]);
        $this->lead = Lead::factory()->create([
            'lead_profile_id' => $profile->id, 'email' => 'ada@acme.test', 'name' => 'Ada Obi', 'company' => 'Acme',
            'title' => 'CEO', 'status' => Lead::STATUS_REPLIED, 'owner_user_id' => $this->owner->id,
        ]);
    }

    private function reply(string $text = 'Yes please, tell me more.'): LeadReply
    {
        $message = EmailMessage::factory()->create(['from_email' => 'ada@acme.test', 'body_text' => $text, 'body_html' => null]);

        return LeadReply::create([
            'lead_id' => $this->lead->id, 'email_message_id' => $message->id, 'status' => LeadReply::STATUS_NEW,
        ]);
    }

    private function handle(LeadReply $reply, array $aiReplies): string
    {
        $this->app->instance(AiClient::class, new ScriptedAiClient($aiReplies));

        return app(ReplyHandler::class)->handle($reply);
    }

    private function verdict(string $class, int $confidence = 90, bool $sensitive = false): string
    {
        return json_encode(['class' => $class, 'confidence' => $confidence, 'sensitive' => $sensitive, 'summary' => 'They want a demo next week.']);
    }

    public function test_an_interested_reply_puts_a_high_priority_card_on_the_owners_leads_board(): void
    {
        $this->handle($this->reply(), [$this->verdict('interested'), self::ANSWER]);

        $board = Board::where('user_id', $this->owner->id)->where('slug', 'leads')->firstOrFail();
        $task = Task::where('board_id', $board->id)->firstOrFail();
        $this->assertStringContainsString('Ada Obi', $task->title);
        $this->assertStringContainsString('Acme', $task->title);
        $this->assertStringContainsString('They want a demo next week.', (string) $task->description);
        $this->assertStringContainsString(route('leads.show', $this->lead), (string) $task->description);
        $this->assertSame(Task::PRIORITY_HIGH, $task->priority);
        $this->assertNotNull($task->due_at);
        $this->assertSame([$this->owner->id], $task->assignees()->pluck('users.id')->all());
        $this->assertDatabaseHas('lead_events', ['lead_id' => $this->lead->id, 'type' => 'task_created']);
    }

    public function test_the_existing_leads_board_is_reused_and_cards_are_appended(): void
    {
        $board = Board::create(['user_id' => $this->owner->id, 'name' => 'Leads', 'slug' => 'leads']);

        $this->handle($this->reply(), [$this->verdict('interested'), self::ANSWER]);
        $other = Lead::factory()->create(['lead_profile_id' => $this->lead->lead_profile_id, 'name' => 'Bo', 'company' => 'Beta', 'owner_user_id' => $this->owner->id]);
        $this->lead = $other;
        $this->handle($this->reply(), [$this->verdict('interested'), self::ANSWER]);

        $this->assertSame(1, Board::where('slug', 'leads')->count());
        $this->assertSame(2, Task::where('board_id', $board->id)->count());
    }

    public function test_the_same_reply_never_makes_two_cards(): void
    {
        $reply = $this->reply();
        $creator = app(LeadFollowUpTask::class);

        $first = $creator->createFor($this->lead, $reply, 'They want a demo.');
        $second = $creator->createFor($this->lead, $reply, 'They want a demo.');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Task::count());
    }

    public function test_replies_that_are_not_a_confident_yes_make_no_card(): void
    {
        $this->handle($this->reply('How much is it?'), [$this->verdict('question'), self::ANSWER]);
        $this->handle($this->reply('Maybe?'), [$this->verdict('interested', 30), self::ANSWER]);
        $this->handle($this->reply('Yes but my lawyer will report you'), [$this->verdict('interested', 90, true)]);
        $this->handle($this->reply('Not now, try in spring.'), [$this->verdict('not_now')]);

        $this->assertSame(0, Task::count());
    }

    public function test_a_lead_nobody_owns_makes_no_card_and_does_not_break_the_reply(): void
    {
        $this->lead->update(['owner_user_id' => null]);
        // The profile creator still counts as the person to tell.
        $this->handle($this->reply(), [$this->verdict('interested'), self::ANSWER]);
        $this->assertSame(1, Task::count());

        Task::query()->forceDelete();
        $this->lead->profile->update(['created_by' => null]);
        $reply = $this->reply();

        $this->assertSame('handled', $this->handle($reply, [$this->verdict('interested'), self::ANSWER]));
        $this->assertSame(0, Task::count());
    }

    public function test_an_archived_leads_board_is_respected_and_the_reply_still_works(): void
    {
        Board::create(['user_id' => $this->owner->id, 'name' => 'Leads', 'slug' => 'leads', 'archived_at' => now()]);

        $result = $this->handle($this->reply(), [$this->verdict('interested'), self::ANSWER]);

        $this->assertSame('handled', $result);
        $this->assertSame(0, Task::count());
    }
}
