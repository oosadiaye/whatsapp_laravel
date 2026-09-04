<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Campaign state-machine guards (review findings #1 pause/resume, #5 update-defer).
 *
 * pause()/resume() previously flipped status unconditionally: pausing a QUEUED
 * campaign before its batch job ran made the job's atomic where(status,QUEUED)
 * claim fail (never fanned out), and resume() then stranded it in RUNNING with
 * 0 contacts. update() launched immediately even for a future scheduled_at,
 * unlike store().
 */
class CampaignLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_pause_is_forbidden_for_a_queued_campaign(): void
    {
        $admin = $this->makeAdmin();
        $campaign = Campaign::factory()->create(['user_id' => $admin->id, 'status' => 'QUEUED']);

        $this->actingAs($admin)
            ->post(route('campaigns.pause', $campaign))
            ->assertForbidden();

        $this->assertSame('QUEUED', $campaign->fresh()->status, 'status unchanged by a forbidden pause');
    }

    public function test_a_running_campaign_can_be_paused_and_resumed(): void
    {
        $admin = $this->makeAdmin();
        $campaign = Campaign::factory()->create(['user_id' => $admin->id, 'status' => 'RUNNING']);

        $this->actingAs($admin)->post(route('campaigns.pause', $campaign))->assertRedirect();
        $this->assertSame('PAUSED', $campaign->fresh()->status);

        $this->actingAs($admin)->post(route('campaigns.resume', $campaign))->assertRedirect();
        $this->assertSame('RUNNING', $campaign->fresh()->status);
    }

    public function test_resume_is_forbidden_for_a_non_paused_campaign(): void
    {
        $admin = $this->makeAdmin();
        $campaign = Campaign::factory()->create(['user_id' => $admin->id, 'status' => 'RUNNING']);

        $this->actingAs($admin)
            ->post(route('campaigns.resume', $campaign))
            ->assertForbidden();

        $this->assertSame('RUNNING', $campaign->fresh()->status);
    }

    public function test_update_with_a_future_schedule_defers_instead_of_launching(): void
    {
        // #5: "Save & Launch" on an edit carrying a future scheduled_at must defer
        // to the cron (like store()), NOT fan the audience out immediately.
        Bus::fake();

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'G']);
        Contact::factory()->create(['user_id' => $admin->id, 'is_active' => true])
            ->each(fn ($c) => $group->contacts()->attach($c->id));

        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'status' => 'DRAFT',
        ]);
        $campaign->contactGroups()->attach($group->id);

        $this->actingAs($admin)
            ->put(route('campaigns.update', $campaign), [
                'name' => 'Edited',
                'message' => 'Hello',
                'groups' => [$group->id],
                'status' => 'QUEUED',
                'scheduled_at' => now()->addHour()->toDateTimeString(),
            ])
            ->assertRedirect();

        $campaign->refresh();
        $this->assertSame('QUEUED', $campaign->status);
        $this->assertNull($campaign->started_at, 'a deferred campaign has not started');
        Bus::assertNotDispatched(CampaignBatchDispatch::class);
    }

    public function test_update_without_a_future_schedule_still_launches_immediately(): void
    {
        // Guard against over-correcting: a "Save & Launch" edit with no future
        // scheduled_at must still launch now.
        Bus::fake();

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'G']);
        Contact::factory()->create(['user_id' => $admin->id, 'is_active' => true])
            ->each(fn ($c) => $group->contacts()->attach($c->id));

        $campaign = Campaign::factory()->create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'status' => 'DRAFT',
        ]);
        $campaign->contactGroups()->attach($group->id);

        $this->actingAs($admin)
            ->put(route('campaigns.update', $campaign), [
                'name' => 'Edited',
                'message' => 'Hello',
                'groups' => [$group->id],
                'status' => 'QUEUED',
            ])
            ->assertRedirect();

        $campaign->refresh();
        $this->assertNotNull($campaign->started_at, 'immediate launch sets started_at');
        Bus::assertDispatched(CampaignBatchDispatch::class);
    }
}
