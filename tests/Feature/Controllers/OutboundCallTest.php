<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_initiate_call(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'calls' => [['id' => 'wacid.test']],
        ], 200)]);

        $this->actingAs($admin)
            ->post(route('conversations.initiateCall', $conv))
            ->assertRedirect(route('conversations.show', $conv))
            ->assertSessionHas('success');

        $this->assertSame(1, CallLog::count());
        $call = CallLog::first();
        $this->assertSame('outbound', $call->direction);
        $this->assertSame($admin->id, $call->placed_by_user_id);
    }

    public function test_user_without_call_permission_gets_403(): void
    {
        // Phase 19a deploy update: the agent/manager/admin/super_admin roles
        // ALL grant conversations.call by default. To validate the policy gate
        // works when the permission is absent, use a user with NO role at all.
        $user = User::factory()->create(['is_active' => true]);

        $admin = $this->makeUser('admin', 'admin@example.com');
        $conv = Conversation::factory()->assignedTo($user)->create(['user_id' => $admin->id]);

        Http::fake();

        $this->actingAs($user)
            ->post(route('conversations.initiateCall', $conv))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, CallLog::count());
    }

    public function test_any_admin_can_initiate_call_on_any_conversation_single_tenant(): void
    {
        // Single-tenant: any admin can initiate a call on any conversation,
        // not just ones tied to their own user_id. Replaces a previous
        // multi-tenant assertion that expected 403 across user boundaries.
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $convOfB = Conversation::factory()->create(['user_id' => $userB->id]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'calls' => [['id' => 'wacid.cross']],
        ], 200)]);

        $this->actingAs($userA)
            ->post(route('conversations.initiateCall', $convOfB))
            ->assertRedirect(route('conversations.show', $convOfB));

        $this->assertSame(1, CallLog::count());
    }

    public function test_meta_failure_does_not_create_orphan_call_log(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Customer not callable'],
        ], 400)]);

        $this->actingAs($admin)
            ->post(route('conversations.initiateCall', $conv))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, CallLog::count());
    }

    public function test_admin_can_end_in_flight_call(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);
        $callLog = \App\Models\CallLog::factory()->inFlight()->outbound($admin)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $this->actingAs($admin)
            ->post(route('conversations.endCall', ['conversation' => $conv, 'call' => $callLog]))
            ->assertRedirect();

        $callLog->refresh();
        $this->assertSame('ended', $callLog->status);
    }

    public function test_any_admin_can_end_any_in_flight_call_single_tenant(): void
    {
        // Single-tenant: any admin can end any in-flight call, regardless of
        // which user originally placed it. Replaces a previous multi-tenant
        // assertion that expected 403 when user A ended user B's call.
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $convB = Conversation::factory()->create(['user_id' => $userB->id]);
        $callB = \App\Models\CallLog::factory()->inFlight()->outbound($userB)->create([
            'conversation_id' => $convB->id,
            'contact_id' => $convB->contact_id,
            'whatsapp_instance_id' => $convB->whatsapp_instance_id,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        $this->actingAs($userA)
            ->post(route('conversations.endCall', ['conversation' => $convB, 'call' => $callB]))
            ->assertRedirect();

        $callB->refresh();
        $this->assertSame('ended', $callB->status);
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
