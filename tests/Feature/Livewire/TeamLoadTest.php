<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\TeamLoad;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TeamLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // TeamLoad now re-authorizes on render (team.view — Livewire updates bypass
        // the route gate). These tests exercise its rendering logic, so act as an
        // authorized viewer. The viewer is created via assignRole() only, so its
        // denormalized `role` column stays the factory default 'user' (not a
        // call-staff value) and it never appears in the rendered roster below.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('super_admin');
        $this->actingAs($viewer);
    }

    public function test_renders_active_agents_with_their_active_count(): void
    {
        $a = $this->makeAgent('Alice');
        $b = $this->makeAgent('Bob');

        // Alice: 1 active conversation, Bob: 2.
        $this->makeAssignedConversation($a, lastInboundAt: now());
        $this->makeAssignedConversation($b, lastInboundAt: now());
        $this->makeAssignedConversation($b, lastInboundAt: now());

        Livewire::test(TeamLoad::class)
            ->assertSeeInOrder(['Alice', '1 / 5', 'Bob', '2 / 5']);
    }

    public function test_excludes_inactive_agents(): void
    {
        $active = $this->makeAgent('ActiveAgent');
        $inactive = $this->makeAgent('InactiveAgent', isActive: false);

        Livewire::test(TeamLoad::class)
            ->assertSee('ActiveAgent')
            ->assertDontSee('InactiveAgent');
    }

    public function test_includes_manager_and_admin_call_staff(): void
    {
        // Managers/admins handle calls alongside agents, so the roster must show
        // them. (Previously this filtered to role=agent only and excluded them.)
        $this->makeStaff('AdminPerson', User::ROLE_ADMIN);
        $this->makeStaff('ManagerPerson', User::ROLE_MANAGER);
        $this->makeAgent('AgentPerson');

        Livewire::test(TeamLoad::class)
            ->assertSee('AgentPerson')
            ->assertSee('AdminPerson')
            ->assertSee('ManagerPerson');
    }

    public function test_does_not_count_old_inbound_conversations(): void
    {
        $agent = $this->makeAgent('Charlie');

        // 1 fresh + 2 old (>24h) — only the fresh one should count.
        $this->makeAssignedConversation($agent, lastInboundAt: now());
        $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(25));
        $this->makeAssignedConversation($agent, lastInboundAt: now()->subHours(48));

        Livewire::test(TeamLoad::class)
            ->assertSee('1 / 5');
    }

    public function test_renders_correct_last_seen_label(): void
    {
        $online = $this->makeAgent('Dana');
        $online->forceFill(['last_seen_at' => now()])->save();

        $stale = $this->makeAgent('Eve');
        $stale->forceFill(['last_seen_at' => now()->subHour()])->save();

        $never = $this->makeAgent('Fred');
        // last_seen_at remains null

        Livewire::test(TeamLoad::class)
            ->assertSee('online')          // Dana
            ->assertSee('1 hour ago')      // Eve (Carbon diffForHumans)
            ->assertSee('never');          // Fred
    }

    private function makeAgent(string $name, bool $isActive = true): User
    {
        return $this->makeStaff($name, User::ROLE_AGENT, $isActive);
    }

    private function makeStaff(string $name, string $role, bool $isActive = true): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower($name).'-'.uniqid().'@example.com',
            'role' => $role,
            'is_active' => $isActive,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeAssignedConversation(
        User $agent,
        Carbon $lastInboundAt,
    ): Conversation {
        $owner = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $owner->id,
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        return Conversation::create([
            'user_id' => $owner->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
            'last_inbound_at' => $lastInboundAt,
            'last_message_at' => $lastInboundAt,
            'unread_count' => 0,
        ]);
    }
}
