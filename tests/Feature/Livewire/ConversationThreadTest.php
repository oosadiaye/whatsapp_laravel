<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ConversationThread;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConversationThreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_thread_renders_conversation_messages(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);
        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => ConversationMessage::DIRECTION_INBOUND,
            'type' => 'text',
            'body' => 'hello from the customer',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ConversationThread::class, ['conversationId' => $conv->id])
            ->assertSee('hello from the customer');
    }

    public function test_agent_cannot_load_thread_for_unassigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]); // unassigned

        Livewire::actingAs($agent)
            ->test(ConversationThread::class, ['conversationId' => $conv->id])
            ->assertStatus(403);
    }
}
