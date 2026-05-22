<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\RealtimePulse;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_returns_empty_payload_for_unauthenticated_user(): void
    {
        Livewire::test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0)
            ->assertViewHas('unreadMessages', 0);
    }

    public function test_admin_sees_inflight_inbound_call_for_their_account(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
            'display_name' => 'Sales Line',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
            'from_phone' => '+2348012345678',
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', function ($calls) use ($conv) {
                return count($calls) === 1
                    && $calls[0]['conversation_id'] === $conv->id
                    && $calls[0]['phone'] === '+2348012345678'
                    && $calls[0]['instance_name'] === 'Sales Line'
                    && $calls[0]['status'] === 'ringing';
            });
    }

    public function test_admin_sees_every_inflight_call_single_tenant(): void
    {
        // Single-tenant: any admin (conversations.view_all) sees every
        // in-flight inbound call across the company, regardless of which
        // user originally owns the conversation row. Replaces a previous
        // multi-tenant assertion that expected user_id scoping.
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $instanceB = WhatsAppInstance::factory()->create([
            'user_id' => $userB->id,
            'status' => 'CONNECTED',
        ]);
        $convB = Conversation::factory()->create([
            'user_id' => $userB->id,
            'whatsapp_instance_id' => $instanceB->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $convB->id,
            'contact_id' => $convB->contact_id,
            'whatsapp_instance_id' => $instanceB->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($userA)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_sees_inflight_call_on_assigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_sees_inflight_call_on_unassigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => null,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_does_not_see_call_assigned_to_someone_else(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $otherAgent = $this->makeUser('agent', 'other@example.com');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $otherAgent->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($agent)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_excludes_calls_with_terminal_status(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        // Create one CallLog per terminal status — none should appear.
        foreach (['ended', 'missed', 'declined', 'failed'] as $terminal) {
            CallLog::factory()->create([
                'conversation_id' => $conv->id,
                'contact_id' => $conv->contact_id,
                'whatsapp_instance_id' => $instance->id,
                'direction' => 'inbound',
                'status' => $terminal,
            ]);
        }

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_excludes_calls_older_than_30_minutes(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
            'created_at' => now()->subMinutes(31),
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_excludes_outbound_calls(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'outbound',
            'status' => 'ringing',
            'placed_by_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
    }

    public function test_unread_message_count_sums_all_conversations_single_tenant(): void
    {
        // Single-tenant: view_all unread count is account-wide. Replaces
        // a previous multi-tenant assertion that excluded conversations
        // owned by other admins (10 vs all-110 here).
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);

        // Conversations owned by admin: 3 + 5 + 2 = 10
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 3,
        ]);
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 5,
        ]);
        Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'unread_count' => 2,
        ]);

        // Conversation under a colleague — now INCLUDED in the count
        // because admin's view_all is account-wide.
        $other = $this->makeUser('admin', 'other@example.com');
        $otherInstance = WhatsAppInstance::factory()->create([
            'user_id' => $other->id,
            'status' => 'CONNECTED',
        ]);
        Conversation::factory()->create([
            'user_id' => $other->id,
            'whatsapp_instance_id' => $otherInstance->id,
            'unread_count' => 100,
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('unreadMessages', 110);
    }

    public function test_caps_payload_to_three_calls_when_more_in_flight(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        // Five concurrent in-flight inbound calls — payload must cap at 3.
        for ($i = 0; $i < 5; $i++) {
            CallLog::factory()->create([
                'conversation_id' => $conv->id,
                'contact_id' => $conv->contact_id,
                'whatsapp_instance_id' => $instance->id,
                'direction' => 'inbound',
                'status' => 'ringing',
            ]);
        }

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 3);
    }

    private function makeUser(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? "{$role}-".uniqid().'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
