<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Deferred scheduling: "Schedule for Later" must NOT dispatch immediately —
 * the campaign is left QUEUED for the campaigns:dispatch-scheduled cron to
 * launch once scheduled_at passes.
 */
class ScheduledCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function storeCampaign(User $admin, array $overrides = []): void
    {
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'G']);

        $this->actingAs($admin)->post(route('campaigns.store'), array_merge([
            'name' => 'Blast',
            'message' => 'Hello',
            'instance_id' => $instance->id,
            'groups' => [$group->id],
            'status' => 'QUEUED',
        ], $overrides))->assertRedirect();
    }

    public function test_future_scheduled_campaign_is_queued_but_not_dispatched(): void
    {
        Bus::fake();

        $this->storeCampaign($this->makeAdmin(), [
            'scheduled_at' => now()->addHour()->toDateTimeString(),
        ]);

        $campaign = Campaign::firstOrFail();
        $this->assertSame('QUEUED', $campaign->status);
        $this->assertNull($campaign->started_at, 'A scheduled campaign has not started yet');
        Bus::assertNotDispatched(CampaignBatchDispatch::class);
    }

    public function test_send_now_campaign_is_dispatched_immediately(): void
    {
        Bus::fake();

        $this->storeCampaign($this->makeAdmin()); // no scheduled_at

        $campaign = Campaign::firstOrFail();
        $this->assertSame('QUEUED', $campaign->status);
        $this->assertNotNull($campaign->started_at);
        Bus::assertDispatched(CampaignBatchDispatch::class);
    }

    public function test_dispatch_scheduled_command_launches_only_due_queued_campaigns(): void
    {
        Bus::fake();

        $due = Campaign::factory()->create(['status' => 'QUEUED', 'scheduled_at' => now()->subMinute()]);
        $future = Campaign::factory()->create(['status' => 'QUEUED', 'scheduled_at' => now()->addHour()]);
        $draft = Campaign::factory()->create(['status' => 'DRAFT', 'scheduled_at' => now()->subMinute()]);

        $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

        Bus::assertDispatchedTimes(CampaignBatchDispatch::class, 1);
        $this->assertNotNull($due->fresh()->started_at, 'due campaign was launched');
        $this->assertNull($future->fresh()->started_at, 'future campaign left alone');
        $this->assertNull($draft->fresh()->started_at, 'draft campaign left alone');
    }
}
