<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the single-tenant visibility contract for WhatsApp instances.
 *
 * Historical context: every controller used to scope WhatsAppInstance
 * queries by user_id = auth()->id(). That was multi-tenant residue —
 * the app has never been a SaaS, just a single Nigerian team using it,
 * but new users joining the app saw an empty /instances list even though
 * the company had connected numbers already provisioned by someone else.
 *
 * The flip: route permissions (`instances.view`, `instances.edit`, etc.)
 * are now the ONLY gate. The user_id column stays in the DB as audit
 * metadata ("who set this up first") but no longer filters queries.
 *
 * These tests would fail if anyone re-introduces a `where('user_id', ...)`
 * scope on the public list/show endpoints.
 */
class InstanceSharedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Stub Meta calls so show() doesn't hit graph.facebook.com.
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '+2348000000000',
                'verified_name' => 'Test Business',
                'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_1K',
            ], 200),
        ]);
    }

    public function test_new_admin_sees_instances_set_up_by_other_users(): void
    {
        // Original owner — created the instance back when the company first
        // signed up with Meta.
        $original = $this->makeAdmin('original@example.com');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $original->id,
            'display_name' => 'Company Main Line',
        ]);

        // New hire joins the company months later, gets given an admin role
        // and logs in for the first time.
        $newHire = $this->makeAdmin('new-hire@example.com');

        $this->actingAs($newHire)
            ->get(route('instances.index'))
            ->assertOk()
            ->assertSee('Company Main Line');
    }

    public function test_admin_can_open_show_page_of_instance_set_up_by_another_user(): void
    {
        $original = $this->makeAdmin('original-show@example.com');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $original->id,
            'status' => 'PENDING_VERIFICATION', // skip Meta probe path in show()
        ]);

        $other = $this->makeAdmin('other-show@example.com');

        $this->actingAs($other)
            ->get(route('instances.show', $instance))
            ->assertOk();
    }

    public function test_setting_default_instance_clears_other_users_defaults_too(): void
    {
        // Single-tenant: there is one global default, not one-per-user.
        // The setDefault transaction must clear the flag account-wide.
        $alice = $this->makeAdmin('alice-default@example.com');
        $bob = $this->makeAdmin('bob-default@example.com');

        $aliceInstance = WhatsAppInstance::factory()->create([
            'user_id' => $alice->id,
            'is_default' => true,
        ]);
        $bobInstance = WhatsAppInstance::factory()->create([
            'user_id' => $bob->id,
            'is_default' => false,
        ]);

        // Bob marks his instance as default.
        $this->actingAs($bob)
            ->post(route('instances.setDefault', $bobInstance))
            ->assertRedirect();

        // Alice's flag should have been cleared (global default semantics).
        $this->assertFalse($aliceInstance->fresh()->is_default);
        $this->assertTrue($bobInstance->fresh()->is_default);
        $this->assertSame(1, WhatsAppInstance::where('is_default', true)->count(),
            'exactly one global default after setDefault');
    }

    public function test_destroy_works_on_instance_set_up_by_another_user(): void
    {
        // Permission gate is the sole authorization layer. `instances.delete`
        // is super_admin only (admin role's syncPermissions array_diffs it
        // out — see RolesAndPermissionsSeeder), so this test uses super_admin.
        // The point: even a super_admin who did not create the instance can
        // delete it, because there is no longer a user_id ownership check.
        $original = $this->makeAdmin('original-destroy@example.com');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $original->id]);

        $superAdmin = User::factory()->create([
            'email' => 'super-destroy@example.com',
            'is_active' => true,
        ]);
        $superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($superAdmin)
            ->delete(route('instances.destroy', $instance))
            ->assertRedirect(route('instances.index'));

        $this->assertNull(WhatsAppInstance::find($instance->id));
    }

    public function test_user_without_view_permission_still_denied(): void
    {
        // Removing the user_id filter does NOT remove the route permission.
        // A user with no role still gets 403 from the middleware.
        $original = $this->makeAdmin('original-permgate@example.com');
        WhatsAppInstance::factory()->create(['user_id' => $original->id]);

        $unprivileged = User::factory()->create(['is_active' => true]);

        $this->actingAs($unprivileged)
            ->get(route('instances.index'))
            ->assertForbidden();
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
}
