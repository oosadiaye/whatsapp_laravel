<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\EmailCampaignDispatch;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledEmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attrs): EmailCampaign
    {
        return EmailCampaign::factory()->create(array_merge(['user_id' => User::factory()], $attrs));
    }

    public function test_launches_a_due_scheduled_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign([
            'status' => EmailCampaign::STATUS_SCHEDULED,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(EmailCampaignDispatch::class);
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaign->fresh()->status);
    }

    public function test_does_not_launch_a_future_scheduled_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign([
            'status' => EmailCampaign::STATUS_SCHEDULED,
            'scheduled_at' => now()->addHour(),
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        Queue::assertNotPushed(EmailCampaignDispatch::class);
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    public function test_rearms_a_finished_weekly_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign([
            'status' => EmailCampaign::STATUS_SENT,
            'recurrence' => EmailCampaign::RECURRENCE_WEEKLY,
            'last_run_at' => now(),
            'sent_count' => 25,
            'completed_at' => now(),
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        $campaign->refresh();
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->status);
        $this->assertTrue($campaign->scheduled_at->greaterThan(now()->addDays(6)));
        $this->assertSame(0, $campaign->sent_count); // counters reset for the next run
    }

    public function test_does_not_rearm_past_recurrence_until(): void
    {
        Queue::fake();
        $campaign = $this->campaign([
            'status' => EmailCampaign::STATUS_SENT,
            'recurrence' => EmailCampaign::RECURRENCE_WEEKLY,
            'last_run_at' => now(),
            'recurrence_until' => now()->addDays(3), // next weekly run (+7d) is past this
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_recurring_campaign_sends_again_after_a_finished_run(): void
    {
        Mail::fake();

        // Audience: one active contact with an email in a group.
        $contact = Contact::factory()->create(['email' => 'lead@example.com', 'is_active' => true]);
        $group = ContactGroup::create(['user_id' => User::factory()->create()->id, 'name' => 'G']);
        $group->contacts()->attach($contact->id);

        $campaign = $this->campaign([
            'status' => EmailCampaign::STATUS_SENT,
            'recurrence' => EmailCampaign::RECURRENCE_WEEKLY,
            'last_run_at' => now()->subWeek(), // next weekly run is due NOW
            'sent_count' => 1,
        ]);
        $campaign->contactGroups()->attach($group->id);

        // Simulate run 1's leftover log — the regression: without the rearm
        // clearing logs, the dispatch's "relaunch safety" skipped every logged
        // contact and recurring campaigns silently sent nothing after run 1.
        EmailLog::factory()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'lead@example.com',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        $campaign->refresh();
        // Run 2 re-sent the full audience: the old log was cleared and a fresh
        // one exists (not 0, not the stale run-1 log counted twice).
        $this->assertSame(1, $campaign->logs()->count());
        $this->assertSame(1, $campaign->sent_count);
        $this->assertDatabaseHas('email_logs', [
            'email_campaign_id' => $campaign->id,
            'email' => 'lead@example.com',
            'status' => EmailLog::STATUS_SENT,
        ]);
    }
}
