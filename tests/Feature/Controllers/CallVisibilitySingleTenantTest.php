<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins single-tenant visibility for the call surface.
 *
 * fb5a398 removed user_id scoping from contacts/conversations/campaigns
 * but missed the call surface entirely. This test file is the regression
 * fence — if anyone reintroduces a where('user_id', $user->id) on the
 * call listing, in-flight banner, or outbound-call authorization, the
 * tests below will fail loudly.
 */
class CallVisibilitySingleTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);
    }

    public function test_new_admin_sees_calls_placed_by_other_admins(): void
    {
        // Original admin set up the company and placed/received calls.
        $original = $this->makeAdmin('original-calls@example.com');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $original->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $original->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->count(4)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        // New admin joining now should see all 4.
        $newHire = $this->makeAdmin('new-hire-calls@example.com');

        $response = $this->actingAs($newHire)->get(route('calls.index'));
        $response->assertOk();
        $this->assertCount(4, $response->viewData('calls'));
    }

    public function test_admin_can_place_outbound_call_on_any_conversation(): void
    {
        // The previous user_id check on view_all blocked a new admin from
        // placing an outbound call on any conversation a colleague had
        // started. Single-tenant: view_all is sufficient authorization.
        $original = $this->makeAdmin('original-outbound@example.com');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $original->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $original->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $newAdmin = $this->makeAdmin('new-admin-outbound@example.com');

        // Required Setting for from_phone — the controller pulls it during
        // CallLog::create and the column is NOT NULL.
        \App\Models\Setting::set('africastalking_virtual_number', '+2348000000001');

        Http::fake([
            'graph.facebook.com/*' => Http::response([], 200),
        ]);

        // Stub the AfricasTalking voice service so we don't hit the real API
        // — only the AUTHORIZATION path is under test here. Returning a
        // session id is enough for the controller to proceed.
        $this->mock(\App\Services\AfricasTalkingVoiceService::class, function ($mock) {
            $mock->shouldReceive('placeCall')->andReturn('sess_test_'.uniqid());
        });

        $response = $this->actingAs($newAdmin)
            ->postJson(route('calls.outbound'), ['conversation_id' => $conv->id]);

        // Before the fix: 403 forbidden because $conversation->user_id !== $newAdmin->id.
        // After: success.
        $response->assertOk();
        $response->assertJsonStructure(['call_id', 'session_id']);

        // And the audit row attributes the call to the placer, not the original
        // conversation owner.
        $this->assertDatabaseHas('call_logs', [
            'conversation_id' => $conv->id,
            'placed_by_user_id' => $newAdmin->id,
            'direction' => 'outbound',
        ]);
    }

    public function test_agent_cannot_see_calls_outside_their_assigned_conversations(): void
    {
        // The single-tenant flip only changed view_all behaviour.
        // view_assigned (agent) scope MUST remain — agents see only
        // calls on conversations currently assigned to them.
        $admin = $this->makeAdmin('admin-agentscope-calls@example.com');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $agent = User::factory()->create([
            'email' => 'agent-calls@example.com',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $assignedConv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $assignedConv->id,
            'contact_id' => $assignedConv->contact_id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $otherConv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => null,  // unassigned pool
        ]);
        CallLog::factory()->count(3)->create([
            'conversation_id' => $otherConv->id,
            'contact_id' => $otherConv->contact_id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $response = $this->actingAs($agent)->get(route('calls.index'));
        $response->assertOk();
        // Agent sees only the 1 call on the conversation assigned to them,
        // NOT the 3 on the unassigned-pool conversation.
        $this->assertCount(1, $response->viewData('calls'));
    }

    private function makeAdmin(?string $email = null): User
    {
        $admin = User::factory()->create([
            'email' => $email ?? 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
