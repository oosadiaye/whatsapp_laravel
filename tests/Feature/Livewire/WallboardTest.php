<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Wallboard;
use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WallboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_renders_live_calls_agents_and_todays_kpis(): void
    {
        $manager = $this->makeUser('manager');

        $agent = User::factory()->create([
            'name' => 'Ada Agent',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
        ]);
        $agent->assignRole('agent');

        $contact = Contact::factory()->create(['name' => 'Live Caller']);
        CallLog::factory()->create([
            'contact_id' => $contact->id,
            'status' => CallLog::STATUS_CONNECTED,
            'direction' => CallLog::DIRECTION_INBOUND,
            'started_at' => now(),
        ]);
        // A missed + an answered call today for the KPIs.
        CallLog::factory()->missed()->create();
        CallLog::factory()->create(['status' => CallLog::STATUS_ENDED, 'connected_at' => now(), 'duration_seconds' => 120]);

        Livewire::actingAs($manager)
            ->test(Wallboard::class)
            ->assertSee('Live Caller')
            ->assertSee('Ada Agent')
            ->assertSee('Live now');
    }

    public function test_wallboard_page_requires_team_view_permission(): void
    {
        $this->actingAs($this->makeUser('manager'))
            ->get(route('wallboard'))
            ->assertOk();

        // Agents don't get team.view.
        $this->actingAs($this->makeUser('agent'))
            ->get(route('wallboard'))
            ->assertForbidden();
    }

    public function test_inbound_live_call_marks_its_assigned_agent_on_call(): void
    {
        $manager = $this->makeUser('manager');

        $agent = User::factory()->create([
            'name' => 'Inbound Handler',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole('agent');

        // Inbound calls have no placer — the assignee of the call's
        // conversation must be credited, or nobody shows "On call".
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'status' => CallLog::STATUS_CONNECTED,
            'started_at' => now(),
        ]);

        Livewire::actingAs($manager)
            ->test(Wallboard::class)
            ->assertSee('Inbound Handler')
            ->assertSee('On call');
    }

    public function test_stale_last_seen_marks_agent_offline(): void
    {
        $manager = $this->makeUser('manager');

        $stale = User::factory()->create([
            'name' => 'Gone Agent',
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
            // last_seen_at outside the availability window — the poll heartbeat
            // stopped, so the board must not keep showing "Available".
            'last_seen_at' => now()->subMinutes(30),
        ]);
        $stale->assignRole('agent');

        Livewire::actingAs($manager)
            ->test(Wallboard::class)
            ->assertSee('Gone Agent')
            ->assertSee('Offline');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
