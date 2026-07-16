<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\EmailCampaignDispatch;
use App\Models\EmailCampaign;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailPauseResumeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    public function test_pause_and_resume_a_scheduled_campaign(): void
    {
        $admin = $this->admin();
        $campaign = EmailCampaign::factory()->scheduled(now()->addDay())->create();

        $this->actingAs($admin)->post(route('email-campaigns.pause', $campaign))->assertRedirect();
        $this->assertSame(EmailCampaign::STATUS_PAUSED, $campaign->fresh()->status);

        $this->actingAs($admin)->post(route('email-campaigns.resume', $campaign))->assertRedirect();
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    public function test_cannot_pause_a_draft(): void
    {
        $admin = $this->admin();
        $campaign = EmailCampaign::factory()->create(['status' => EmailCampaign::STATUS_DRAFT]);

        $this->actingAs($admin)->post(route('email-campaigns.pause', $campaign))->assertForbidden();
    }

    public function test_the_cron_does_not_launch_a_paused_campaign(): void
    {
        Queue::fake();
        $this->admin();
        EmailCampaign::factory()->create([
            'status' => EmailCampaign::STATUS_PAUSED,
            'scheduled_at' => now()->subMinute(), // due, but paused
        ]);

        $this->artisan('email:dispatch-scheduled')->assertSuccessful();

        Queue::assertNotPushed(EmailCampaignDispatch::class);
    }
}
