<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the campaigns bulk-delete contract:
 *   - Owner can delete their own campaigns
 *   - Cross-account IDs in the payload are silently filtered (whereIn +
 *     where(user_id) at the SQL layer)
 *   - RUNNING campaigns are skipped, not deleted, and counted in the
 *     flash message
 *   - Permission gate: campaigns.delete required
 *   - Validation rejects empty / non-array `ids`
 *
 * This route is the only bulk-mutation endpoint on the campaigns surface;
 * regressions here would be silent (deleting too much or refusing valid
 * deletes) and only show up when an operator notices their list got
 * shorter than they remember.
 */
class CampaignBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_bulk_delete_their_own_campaigns(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeCampaign($admin, 'DRAFT');
        $b = $this->makeCampaign($admin, 'COMPLETED');
        $c = $this->makeCampaign($admin, 'CANCELLED');

        $this->actingAs($admin)
            ->post(route('campaigns.bulkDestroy'), ['ids' => [$a->id, $b->id, $c->id]])
            ->assertRedirect(route('campaigns.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, Campaign::count());
    }

    public function test_running_campaigns_are_skipped_not_deleted(): void
    {
        $admin = $this->makeAdmin();
        $deletable = $this->makeCampaign($admin, 'COMPLETED');
        $running = $this->makeCampaign($admin, 'RUNNING');

        $this->actingAs($admin)
            ->post(route('campaigns.bulkDestroy'), ['ids' => [$deletable->id, $running->id]])
            ->assertRedirect(route('campaigns.index'));

        $this->assertNull(Campaign::find($deletable->id), 'deletable should be removed');
        $this->assertNotNull(Campaign::find($running->id), 'running should be preserved');

        // Flash should mention both counts so the operator knows about the skip.
        $flash = session('success') ?? session('error');
        $this->assertStringContainsString('Deleted 1 campaign', $flash);
        $this->assertStringContainsString('Skipped 1 running campaign', $flash);
    }

    public function test_any_admin_can_bulk_delete_any_campaigns_single_tenant(): void
    {
        // Single-tenant: any user with campaigns.delete (admin / super_admin)
        // can bulk-delete any campaigns, regardless of which user originally
        // created each one. Replaces a previous multi-tenant assertion that
        // expected cross-account IDs to be silently filtered out.
        $alice = $this->makeAdmin('alice@example.com');
        $bob   = $this->makeAdmin('bob@example.com');

        $aliceCampaign = $this->makeCampaign($alice, 'DRAFT');
        $bobCampaign   = $this->makeCampaign($bob, 'DRAFT');

        $this->actingAs($alice)
            ->post(route('campaigns.bulkDestroy'), ['ids' => [$aliceCampaign->id, $bobCampaign->id]])
            ->assertRedirect();

        // Both deleted now — single-tenant means "ownership" no longer
        // restricts who can act on a row.
        $this->assertNull(Campaign::find($aliceCampaign->id));
        $this->assertNull(Campaign::find($bobCampaign->id));
    }

    public function test_user_without_campaigns_delete_permission_gets_403(): void
    {
        // No role at all — campaigns.delete is admin/super_admin only.
        $user = User::factory()->create(['is_active' => true]);
        $admin = $this->makeAdmin();
        $campaign = $this->makeCampaign($admin, 'DRAFT');

        $this->actingAs($user)
            ->post(route('campaigns.bulkDestroy'), ['ids' => [$campaign->id]])
            ->assertForbidden();

        $this->assertNotNull(Campaign::find($campaign->id));
    }

    public function test_empty_ids_array_rejected_by_validator(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('campaigns.bulkDestroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    public function test_missing_ids_payload_rejected_by_validator(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('campaigns.bulkDestroy'), [])
            ->assertSessionHasErrors('ids');
    }

    private function makeAdmin(?string $email = null): User
    {
        $admin = User::factory()->create([
            'email' => $email ?? 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeCampaign(User $owner, string $status): Campaign
    {
        $instance = WhatsAppInstance::factory()->create(['user_id' => $owner->id]);

        return Campaign::create([
            'user_id' => $owner->id,
            'instance_id' => $instance->id,
            'name' => 'Test '.uniqid(),
            'message' => 'hi',
            'status' => $status,
        ]);
    }
}
