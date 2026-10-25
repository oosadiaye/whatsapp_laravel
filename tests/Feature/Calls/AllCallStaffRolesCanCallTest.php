<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallFlowRouter;
use App\Services\RoundRobinAssigner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every call-staff role — super admin, admin, manager and agent — must be able to
 * place calls, run the softphone, and be routed incoming calls. Admins are not a
 * special case that quietly falls out of any step of that chain.
 */
class AllCallStaffRolesCanCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::setEncrypted('africastalking_api_key', 'test-key');
        config([
            'voice.business_hours_enabled' => false,
            'voice.ivr_enabled' => false,
            'voice.queue_enabled' => false,
            'voice.voicemail_enabled' => false,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return [
            'super admin' => [User::ROLE_SUPER_ADMIN],
            'admin' => [User::ROLE_ADMIN],
            'manager' => [User::ROLE_MANAGER],
            'agent' => [User::ROLE_AGENT],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_holds_the_permissions_to_place_calls(string $role): void
    {
        $user = $this->staff($role);

        $this->assertTrue($user->can('calls.view'), "$role: calls.view");
        $this->assertTrue($user->can('calls.dial'), "$role: calls.dial");
        $this->assertTrue($user->can('conversations.call'), "$role: conversations.call");
    }

    #[DataProvider('roles')]
    public function test_role_gets_a_softphone_token_and_the_workspace_dial_pad(string $role): void
    {
        $user = $this->staff($role);

        $this->actingAs($user)->get(route('calls.workspace'))
            ->assertOk()
            ->assertSee('name="at-voice-token"', false)
            ->assertSee('agent_'.$user->id, false);
    }

    #[DataProvider('roles')]
    public function test_role_is_available_and_picked_by_round_robin_when_online(string $role): void
    {
        $user = $this->staff($role);
        $assigner = app(RoundRobinAssigner::class);

        $this->assertTrue($assigner->isAvailable($user), "$role should count as available");
        $this->assertSame($user->id, $assigner->next()?->id, "$role should be picked when they are the only agent online");
    }

    #[DataProvider('roles')]
    public function test_an_incoming_call_assigned_to_the_role_dials_their_browser(string $role): void
    {
        $user = $this->staff($role);
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $user->id]);
        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => CallLog::DIRECTION_INBOUND,
            'from_phone' => '+2348011112222',
        ]);

        $xml = app(CallFlowRouter::class)->entryXml($call)->render();

        $this->assertStringContainsString('<Dial phoneNumbers="sandbox.agent_'.$user->id.'"', $xml, "$role should be dialed");
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'presence_status' => User::PRESENCE_AVAILABLE,
            'last_seen_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
