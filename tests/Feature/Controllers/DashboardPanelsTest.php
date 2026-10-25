<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\Campaign;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's call / inbox / team panels show real data, and only to people who
 * could open the same data elsewhere in the app.
 */
class DashboardPanelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_greets_the_user_and_says_when_nothing_needs_attention(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN, 'Amaka Obi');

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Amaka.', false)
            ->assertSee('Nothing needs your attention right now.');
    }

    public function test_a_live_call_is_listed_and_counted_in_the_summary(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $contact = Contact::factory()->create(['name' => 'Chidi Okafor']);
        CallLog::factory()->create([
            'contact_id' => $contact->id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'status' => CallLog::STATUS_CONNECTED,
            'started_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Chidi Okafor')
            ->assertSee('1 call is live');
    }

    public function test_a_stale_in_flight_call_is_not_shown_as_live(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $contact = Contact::factory()->create(['name' => 'Ghost Caller']);
        $call = CallLog::factory()->create([
            'contact_id' => $contact->id,
            'status' => CallLog::STATUS_RINGING,
        ]);
        $call->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ghost Caller');
    }

    public function test_needs_you_counts_missed_calls_unassigned_chats_and_failed_sends(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        CallLog::factory()->count(2)->create(['status' => CallLog::STATUS_MISSED]);
        Conversation::factory()->create(['assigned_to_user_id' => null, 'unread_count' => 3]);
        Conversation::factory()->create(['assigned_to_user_id' => $admin->id, 'unread_count' => 5]); // assigned: not counted

        $campaign = Campaign::factory()->create();
        $contact = Contact::factory()->create();
        MessageLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone' => '2348000000009', 'status' => 'FAILED', 'sent_at' => now()]);

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertSame(2, $response->viewData('missedCalls'));
        $this->assertSame(1, $response->viewData('unassignedChats'));
        $this->assertSame(1, $response->viewData('failedToday'));
        $response->assertSee('4 things need you');
    }

    public function test_the_team_list_only_appears_for_people_who_can_view_the_team(): void
    {
        $online = $this->staff(User::ROLE_AGENT, 'Tunde Bello');
        $online->forceFill(['last_seen_at' => now(), 'presence_status' => User::PRESENCE_AVAILABLE])->save();

        $manager = $this->staff(User::ROLE_MANAGER);
        $agent = $this->staff(User::ROLE_AGENT);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Tunde Bello');
        $this->actingAs($agent)->get(route('dashboard'))->assertOk()->assertDontSee('Tunde Bello');
    }

    public function test_the_inbox_figures_are_hidden_from_people_without_inbox_access(): void
    {
        // Agents see only their assigned chats, so the company-wide unassigned count is not theirs.
        $agent = $this->staff(User::ROLE_AGENT);
        Conversation::factory()->create(['assigned_to_user_id' => null, 'unread_count' => 4]);

        $this->assertSame(0, $this->actingAs($agent)->get(route('dashboard'))->assertOk()->viewData('unassignedChats'));
    }

    private function staff(string $role, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter([
            'role' => $role,
            'is_active' => true,
            'name' => $name,
        ]));
        $user->assignRole($role);

        return $user;
    }
}
