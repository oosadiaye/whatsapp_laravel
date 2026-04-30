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

    public function test_agent_without_call_permission_gets_403(): void
    {
        $agent = $this->makeUser('agent');
        $admin = $this->makeUser('admin', 'admin@example.com');
        $conv = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);

        Http::fake();

        $this->actingAs($agent)
            ->post(route('conversations.initiateCall', $conv))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, CallLog::count());
    }

    public function test_cross_account_call_is_forbidden(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $convOfB = Conversation::factory()->create(['user_id' => $userB->id]);

        Http::fake();

        $this->actingAs($userA)
            ->post(route('conversations.initiateCall', $convOfB))
            ->assertForbidden();

        Http::assertNothingSent();
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
