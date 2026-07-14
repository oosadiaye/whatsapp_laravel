<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\EmailCampaignDispatch;
use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
