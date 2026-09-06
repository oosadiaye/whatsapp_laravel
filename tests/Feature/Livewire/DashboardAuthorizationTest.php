<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\CallCostDashboard;
use App\Livewire\CallQueue;
use App\Livewire\CampaignStatus;
use App\Livewire\MessageLogsTable;
use App\Livewire\TeamLoad;
use App\Livewire\Wallboard;
use App\Models\Campaign;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review L1: Livewire poll/update requests hit livewire/update (web middleware
 * only) and bypass the page's route permission gate, so each dashboard must
 * re-authorize inside render(). Without it, an agent whose permission is revoked
 * mid-session keeps receiving live data on their still-open tab. These tests pin
 * the guard on every affected component.
 */
class DashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A user with no role → no permissions. */
    private function powerless(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_team_load_is_forbidden_without_team_view(): void
    {
        Livewire::actingAs($this->powerless())->test(TeamLoad::class)->assertForbidden();
    }

    public function test_wallboard_is_forbidden_without_team_view(): void
    {
        Livewire::actingAs($this->powerless())->test(Wallboard::class)->assertForbidden();
    }

    public function test_call_cost_dashboard_is_forbidden_without_reports_view(): void
    {
        Livewire::actingAs($this->powerless())->test(CallCostDashboard::class)->assertForbidden();
    }

    public function test_call_queue_is_forbidden_without_conversation_view(): void
    {
        Livewire::actingAs($this->powerless())->test(CallQueue::class)->assertForbidden();
    }

    public function test_campaign_status_is_forbidden_without_campaigns_view(): void
    {
        $campaign = Campaign::factory()->create();

        Livewire::actingAs($this->powerless())
            ->test(CampaignStatus::class, ['campaignId' => $campaign->id])
            ->assertForbidden();
    }

    public function test_message_logs_table_is_forbidden_without_campaigns_view(): void
    {
        $campaign = Campaign::factory()->create();

        Livewire::actingAs($this->powerless())
            ->test(MessageLogsTable::class, ['campaignId' => $campaign->id])
            ->assertForbidden();
    }

    public function test_authorized_admin_can_render_each_dashboard(): void
    {
        $admin = $this->admin();
        $campaign = Campaign::factory()->create();

        Livewire::actingAs($admin)->test(TeamLoad::class)->assertOk();
        Livewire::actingAs($admin)->test(Wallboard::class)->assertOk();
        Livewire::actingAs($admin)->test(CallCostDashboard::class)->assertOk();
        Livewire::actingAs($admin)->test(CallQueue::class)->assertOk();
        Livewire::actingAs($admin)->test(CampaignStatus::class, ['campaignId' => $campaign->id])->assertOk();
        Livewire::actingAs($admin)->test(MessageLogsTable::class, ['campaignId' => $campaign->id])->assertOk();
    }
}
