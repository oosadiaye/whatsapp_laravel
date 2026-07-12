<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Wallboard;
use App\Models\CallLog;
use App\Models\Contact;
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

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
