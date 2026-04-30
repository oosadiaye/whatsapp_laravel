<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\ContactGroup;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the ContactGroup CRUD flow.
 *
 * Originally untested — surfaced when a production user reported "Create
 * Group button does nothing". The button itself was an Alpine UI bug
 * (button outside x-data scope, see groups/index.blade.php fix), but the
 * lack of any controller test let several other latent issues hide:
 *   - permission gate (groups.create) wasn't being verified
 *   - the GET /groups/create route doesn't exist (modal-based create flow);
 *     POST /groups must accept directly without redirecting through it
 *   - ContactGroup factory was missing (creating one for these tests)
 */
class ContactGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_a_group(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('groups.store'), [
                'name' => 'VIP Customers',
                'description' => 'High-value accounts',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $group = ContactGroup::first();
        $this->assertNotNull($group);
        $this->assertSame('VIP Customers', $group->name);
        $this->assertSame($admin->id, $group->user_id);
    }

    public function test_create_with_only_name_succeeds(): void
    {
        // Description is optional — empty form should still create the group.
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('groups.store'), ['name' => 'Just a name'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, ContactGroup::count());
    }

    public function test_create_without_name_fails_validation(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('groups.store'), ['description' => 'Body but no name'])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, ContactGroup::count());
    }

    public function test_agent_cannot_create_groups(): void
    {
        // Agent role lacks groups.create — should 403 before reaching controller.
        $agent = $this->makeUser('agent');

        $this->actingAs($agent)
            ->post(route('groups.store'), ['name' => 'Should fail'])
            ->assertForbidden();

        $this->assertSame(0, ContactGroup::count());
    }

    public function test_groups_index_renders_for_admin(): void
    {
        // Smoke test for the page that hosts the create-group button.
        // Catches "blank page" regressions caused by view template errors.
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('groups.index'))
            ->assertOk()
            ->assertSee('Contact Groups')
            ->assertSee('Create Group');  // The button must be in the rendered HTML
    }

    public function test_groups_index_hides_create_button_from_users_without_permission(): void
    {
        // The @can('groups.create') gate around the button hides it for
        // users who can see the page but can't create.
        $agent = $this->makeUser('agent');

        $response = $this->actingAs($agent)->get(route('groups.index'));

        $response->assertOk();
        // Agent can view groups but should not see the create button text.
        $this->assertStringNotContainsString('Create Group', $response->getContent());
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
