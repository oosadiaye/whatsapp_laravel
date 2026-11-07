<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\SendUserEmail;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Models\LeadReply;
use App\Models\User;
use App\Services\Leads\Outreach\AutonomyPolicy;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LeadApprovalScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeadProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->admin = User::factory()->create()->assignRole('admin');
        $account = EmailAccount::factory()->create(['user_id' => $this->admin->id]);
        $this->profile = LeadProfile::factory()->active()->create(['sender_email_account_id' => $account->id, 'created_by' => $this->admin->id]);
    }

    private function pending(array $o = []): LeadMessage
    {
        $lead = Lead::factory()->create(['lead_profile_id' => $this->profile->id, 'email' => fake()->unique()->safeEmail(), 'status' => Lead::STATUS_QUALIFIED]);

        return LeadMessage::create(array_merge(['lead_id' => $lead->id, 'kind' => 'first', 'status' => 'pending_approval', 'subject' => 'Hello', 'body' => 'A reasonably long body.'], $o));
    }

    public function test_the_queue_lists_waiting_drafts_and_needs_human_replies(): void
    {
        $message = $this->pending(['subject' => 'Invoicing for Acme']);
        $lead = Lead::factory()->create(['lead_profile_id' => $this->profile->id, 'email' => 'r@acme.test', 'name' => 'Rita Reply']);
        LeadReply::create(['lead_id' => $lead->id, 'status' => 'needs_human', 'summary' => 'Sounds angry.']);

        $this->actingAs($this->admin)->get(route('lead-approvals.index'))
            ->assertOk()
            ->assertSee('Invoicing for Acme')
            ->assertSee('Rita Reply')
            ->assertSee('Sounds angry.');
    }

    public function test_opening_the_queue_marks_the_replies_as_seen_and_clears_the_badge(): void
    {
        $lead = Lead::factory()->create(['lead_profile_id' => $this->profile->id]);
        LeadReply::create(['lead_id' => $lead->id, 'status' => 'needs_human']);

        $this->actingAs($this->admin)->getJson(route('lead-approvals.attention'))->assertJson(['replies' => 1, 'total' => 1]);
        $this->actingAs($this->admin)->get(route('lead-approvals.index'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('lead-approvals.attention'))->assertJson(['replies' => 0, 'total' => 0]);
    }

    public function test_approving_sends_the_email_and_records_who_approved(): void
    {
        $message = $this->pending();

        $this->actingAs($this->admin)->post(route('lead-approvals.approve', $message))->assertRedirect();

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame($this->admin->id, $message->decided_by);
        Queue::assertPushed(SendUserEmail::class);
        $this->assertDatabaseHas('lead_events', ['lead_id' => $message->lead_id, 'type' => 'draft_approved']);
    }

    public function test_editing_before_approving_sends_the_edited_text_and_marks_it_as_a_person_s(): void
    {
        $message = $this->pending();

        $this->actingAs($this->admin)->post(route('lead-approvals.approve', $message), [
            'subject' => 'My own subject', 'body' => 'My own much better wording of the email.',
        ])->assertRedirect();

        $message->refresh();
        $this->assertSame('My own subject', $message->subject);
        $this->assertFalse($message->by_ai);
        Queue::assertPushed(SendUserEmail::class, fn (SendUserEmail $job) => $job->email->subject === 'My own subject');
    }

    public function test_discarding_sends_nothing(): void
    {
        $message = $this->pending();

        $this->actingAs($this->admin)->post(route('lead-approvals.discard', $message))->assertRedirect();

        $this->assertSame('discarded', $message->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_message_that_was_already_handled_cannot_be_approved_twice(): void
    {
        $message = $this->pending();

        $this->actingAs($this->admin)->post(route('lead-approvals.approve', $message));
        $this->actingAs($this->admin)->post(route('lead-approvals.approve', $message));

        Queue::assertPushed(SendUserEmail::class, 1);
    }

    public function test_approving_while_paused_keeps_it_waiting_for_when_automation_resumes(): void
    {
        LeadConfig::setKillSwitch(true);
        $message = $this->pending();

        $this->actingAs($this->admin)->post(route('lead-approvals.approve', $message))->assertSessionHas('success');

        $this->assertSame('approved', $message->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_an_agent_without_the_permission_cannot_see_or_act_on_the_queue(): void
    {
        $agent = User::factory()->create()->assignRole('agent');
        $message = $this->pending();

        $this->actingAs($agent)->get(route('lead-approvals.index'))->assertForbidden();
        $this->actingAs($agent)->post(route('lead-approvals.approve', $message))->assertForbidden();
        $this->actingAs($agent)->post(route('lead-approvals.discard', $message))->assertForbidden();
        $this->actingAs($agent)->getJson(route('lead-approvals.attention'))->assertForbidden();
        $this->assertSame('pending_approval', $message->fresh()->status);
    }

    public function test_the_lead_page_shows_its_emails_and_replies(): void
    {
        $message = $this->pending(['status' => 'sent', 'subject' => 'Sent subject line']);
        $reply = LeadReply::create(['lead_id' => $message->lead_id, 'status' => 'handled', 'classification' => 'interested', 'confidence' => 88]);

        $this->actingAs($this->admin)->get(route('leads.show', $message->lead_id))
            ->assertOk()
            ->assertSee('Sent subject line')
            ->assertSee('interested');
        $this->assertNotNull($reply->fresh()->seen_at);
    }

    public function test_outreach_settings_are_saved_with_the_profile(): void
    {
        $account = EmailAccount::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->put(route('lead-profiles.update', $this->profile), [
            'name' => 'P', 'brief' => 'Founders and CEOs of small fintech companies in Lagos.', 'sources' => ['apollo'], 'daily_pull_cap' => 50,
            'autonomy' => AutonomyPolicy::FULL_AUTO, 'sender_email_account_id' => $account->id, 'playbook' => 'We sell invoicing software.',
            'followup_count' => 1, 'followup_delay_days' => 5, 'daily_send_cap' => 7,
        ])->assertSessionHasNoErrors();

        $profile = $this->profile->fresh();
        $this->assertSame(AutonomyPolicy::FULL_AUTO, $profile->autonomy);
        $this->assertSame($account->id, $profile->sender_email_account_id);
        $this->assertSame(1, $profile->followup_count);
        $this->assertSame(5, $profile->followup_delay_days);
        $this->assertSame(7, $profile->daily_send_cap);
    }

    public function test_a_profile_cannot_send_as_a_colleagues_mailbox(): void
    {
        $theirs = EmailAccount::factory()->create();

        $this->actingAs($this->admin)->put(route('lead-profiles.update', $this->profile), [
            'name' => 'P', 'brief' => 'Founders and CEOs of small fintech companies in Lagos.', 'sources' => ['apollo'], 'daily_pull_cap' => 50,
            'sender_email_account_id' => $theirs->id,
        ])->assertSessionHasErrors('sender_email_account_id');
    }

    public function test_an_unknown_autonomy_level_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('lead-profiles.update', $this->profile), [
            'name' => 'P', 'brief' => 'Founders and CEOs of small fintech companies in Lagos.', 'sources' => ['apollo'], 'daily_pull_cap' => 50,
            'autonomy' => 'do_whatever',
        ])->assertSessionHasErrors('autonomy');
    }

    public function test_the_global_default_is_saved_and_shown_on_lead_sources(): void
    {
        $this->actingAs($this->admin)->post(route('lead-sources.autonomy'), ['level' => AutonomyPolicy::AUTO_FIRST])->assertRedirect();

        $this->assertSame(AutonomyPolicy::AUTO_FIRST, LeadConfig::autonomyDefault());
        $this->actingAs($this->admin)->get(route('lead-sources.index'))->assertOk()->assertSee('Semi-automatic');
        $this->actingAs($this->admin)->post(route('lead-sources.autonomy'), ['level' => 'nonsense'])->assertSessionHasErrors('level');
    }
}
