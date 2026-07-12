<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\CallInsightsPanel;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CallInsightsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_renders_ai_summary_and_key_points_when_completed(): void
    {
        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create([
            'ai_status' => CallLog::AI_STATUS_COMPLETED,
            'ai_summary' => 'Customer chased order 812.',
            'ai_key_points' => ['Order 812 late', 'Refund requested'],
        ]);

        Livewire::actingAs($admin)
            ->test(CallInsightsPanel::class, ['callId' => $call->id])
            ->assertSee('Customer chased order 812.')
            ->assertSee('Order 812 late')
            ->assertSee('Refund requested');
    }

    public function test_agent_can_add_a_note_through_the_panel(): void
    {
        $agent = $this->makeUser('agent');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conversation->id]);

        Livewire::actingAs($agent)
            ->test(CallInsightsPanel::class, ['callId' => $call->id])
            ->set('noteBody', 'Follow up on Friday.')
            ->call('addNote')
            ->assertHasNoErrors()
            ->assertSet('noteBody', '');

        $this->assertDatabaseHas('call_notes', [
            'call_log_id' => $call->id,
            'user_id' => $agent->id,
            'body' => 'Follow up on Friday.',
        ]);
    }

    public function test_empty_note_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $call = CallLog::factory()->create();

        Livewire::actingAs($admin)
            ->test(CallInsightsPanel::class, ['callId' => $call->id])
            ->set('noteBody', '')
            ->call('addNote')
            ->assertHasErrors('noteBody');

        $this->assertDatabaseCount('call_notes', 0);
    }

    public function test_panel_denies_access_to_a_call_the_agent_cannot_see(): void
    {
        $agent = $this->makeUser('agent');
        $other = $this->makeUser('agent', 'other-panel@example.com');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $other->id]);
        $call = CallLog::factory()->create(['conversation_id' => $conversation->id]);

        Livewire::actingAs($agent)
            ->test(CallInsightsPanel::class, ['callId' => $call->id])
            ->assertStatus(403);
    }

    private function makeUser(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? $role.'-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
