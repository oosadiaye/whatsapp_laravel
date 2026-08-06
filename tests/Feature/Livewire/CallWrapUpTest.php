<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\CallWrapUp;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CallWrapUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_assigned_agent_can_save_disposition_and_note(): void
    {
        $agent = $this->makeUser('agent');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);
        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'disposition' => null,
            'ended_at' => now(),
        ]);

        Livewire::actingAs($agent)
            ->test(CallWrapUp::class, ['call' => $call])
            ->set('disposition', 'qualified_lead')
            ->set('wrapUpNote', 'Wants a callback tomorrow.')
            ->call('save')
            ->assertHasNoErrors();

        $call->refresh();
        $this->assertSame('qualified_lead', $call->disposition);
        $this->assertNotNull($call->wrap_up_at);

        $this->assertDatabaseHas('call_notes', [
            'call_log_id' => $call->id,
            'user_id' => $agent->id,
            'body' => 'Wants a callback tomorrow.',
        ]);
    }

    public function test_save_is_forbidden_for_an_agent_who_cannot_see_the_call(): void
    {
        $agent = $this->makeUser('agent');
        $other = $this->makeUser('agent', 'other-wrapup@example.com');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $other->id]);
        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'disposition' => null,
            'ended_at' => now(),
        ]);

        Livewire::actingAs($agent)
            ->test(CallWrapUp::class, ['call' => $call])
            ->set('disposition', 'other')
            ->call('save')
            ->assertStatus(403);

        $this->assertNull($call->fresh()->disposition);
        $this->assertDatabaseCount('call_notes', 0);
    }

    public function test_view_all_user_can_save_on_any_call(): void
    {
        $admin = $this->makeUser('admin');
        $other = $this->makeUser('agent', 'other-wrapup-admin@example.com');
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $other->id]);
        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'disposition' => null,
            'ended_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(CallWrapUp::class, ['call' => $call])
            ->set('disposition', 'answered')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('answered', $call->fresh()->disposition);
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
