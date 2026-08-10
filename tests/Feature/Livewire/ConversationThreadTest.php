<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ConversationThread;
use App\Models\CallLog;
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

    public function test_thread_excludes_africas_talking_calls_but_keeps_whatsapp_calls(): void
    {
        // AT (softphone) calls must NOT clutter the chat — they belong in the
        // Call Workspace / Call History / incoming-call screen. Only WhatsApp/Meta
        // calls appear in the conversation timeline.
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        $atCall = CallLog::create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'status' => CallLog::STATUS_DECLINED,
            'started_at' => now(),
            'from_phone' => '2348000000000',
            'to_phone' => '2348111111111',
        ]);
        $metaCall = CallLog::create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'provider' => CallLog::PROVIDER_META_WHATSAPP,
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now(),
            'from_phone' => '2348000000000',
            'to_phone' => '2348111111111',
        ]);

        Livewire::actingAs($admin)
            ->test(ConversationThread::class, ['conversationId' => $conv->id])
            ->assertViewHas('timeline', function ($timeline) use ($atCall, $metaCall) {
                $callIds = $timeline
                    ->filter(fn ($i) => $i instanceof CallLog)
                    ->pluck('id')
                    ->all();

                return in_array($metaCall->id, $callIds, true)
                    && ! in_array($atCall->id, $callIds, true);
            });
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
