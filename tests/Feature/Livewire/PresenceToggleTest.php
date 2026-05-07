<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\PresenceToggle;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PresenceToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_component_mounts_with_users_current_status(): void
    {
        $agent = $this->makeAgent();
        $agent->forceFill(['presence_status' => User::PRESENCE_BUSY])->save();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->assertSet('status', User::PRESENCE_BUSY);
    }

    public function test_set_status_updates_database(): void
    {
        $agent = $this->makeAgent();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_AWAY)
            ->assertSet('status', User::PRESENCE_AWAY);

        $agent->refresh();
        $this->assertSame(User::PRESENCE_AWAY, $agent->presence_status);
    }

    public function test_set_status_stamps_set_at_timestamp(): void
    {
        $agent = $this->makeAgent();
        $this->assertNull($agent->presence_status_set_at);

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_BUSY);

        $agent->refresh();
        $this->assertNotNull($agent->presence_status_set_at);
        $this->assertTrue(
            $agent->presence_status_set_at->diffInSeconds(now()) < 5,
            'presence_status_set_at must be stamped to ~now() on status change'
        );
    }

    public function test_set_status_rejects_invalid_string(): void
    {
        $agent = $this->makeAgent();
        // baseline: column default is 'available' (refresh to read DB default,
        // which Eloquent's create() doesn't roundtrip into the in-memory model)
        $agent->refresh();
        $this->assertSame(User::PRESENCE_AVAILABLE, $agent->presence_status);

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', 'partying');

        $agent->refresh();
        $this->assertSame(
            User::PRESENCE_AVAILABLE,
            $agent->presence_status,
            'Invalid status string must be silently rejected — DB unchanged'
        );
    }

    public function test_component_renders_correct_status_label(): void
    {
        $agent = $this->makeAgent();

        Livewire::actingAs($agent)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_AWAY)
            ->assertSee('Away');
    }

    public function test_component_requires_authentication(): void
    {
        $this->expectException(\Throwable::class);

        // Without actingAs(), Auth::user() is null inside the component.
        // Mount itself dereferences Auth::user()->presence_status, so this
        // raises an exception. (Production view-level @if guard prevents
        // this code path — this test asserts the component doesn't silently
        // succeed for guests.)
        Livewire::test(PresenceToggle::class);
    }

    public function test_non_agent_users_can_also_set_status(): void
    {
        // The agent-only mount is enforced in navigation.blade.php (next task),
        // not in the component. The component itself accepts any authenticated
        // user — guards against future "managers can also be in rotation"
        // changes that would mount the component for non-agents.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        Livewire::actingAs($admin)
            ->test(PresenceToggle::class)
            ->call('setStatus', User::PRESENCE_BUSY)
            ->assertSet('status', User::PRESENCE_BUSY);

        $admin->refresh();
        $this->assertSame(User::PRESENCE_BUSY, $admin->presence_status);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);
        $agent->assignRole(User::ROLE_AGENT);

        return $agent;
    }
}
