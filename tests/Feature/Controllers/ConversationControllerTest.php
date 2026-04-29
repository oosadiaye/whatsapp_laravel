<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Inbox visibility + reply flow tests.
 *
 * Covers the permission boundary that determines who sees what:
 *   - admin/manager: all conversations in their account
 *   - agent: only conversations assigned to them
 *   - everyone else: 403
 *
 * And the reply flow:
 *   - freeform send within 24h window → outbound message stored
 *   - freeform send outside window → blocked with friendly error
 *   - template send works any time
 *   - cross-account access blocked
 */
class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sees_all_conversations_in_account(): void
    {
        $admin = $this->makeUser('admin');
        Conversation::factory()->count(3)->create(['user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('conversations.index'));

        $response->assertOk();
        $this->assertCount(3, $response->viewData('conversations'));
    }

    public function test_admin_does_not_see_conversations_from_other_accounts(): void
    {
        $admin = $this->makeUser('admin');
        $otherUser = $this->makeUser('admin', 'other@example.com');

        Conversation::factory()->count(2)->create(['user_id' => $admin->id]);
        Conversation::factory()->count(5)->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($admin)->get(route('conversations.index'));

        $this->assertCount(2, $response->viewData('conversations'));
    }

    public function test_agent_sees_only_assigned_conversations(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');

        // 3 conversations under admin's account: 1 assigned to agent, 2 unassigned
        $assigned = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);
        Conversation::factory()->count(2)->create(['user_id' => $admin->id]);

        $response = $this->actingAs($agent)->get(route('conversations.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('conversations'));
        $this->assertSame($assigned->id, $response->viewData('conversations')->first()->id);
    }

    public function test_unassigned_filter_works_for_admin(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');

        Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);
        Conversation::factory()->count(2)->create(['user_id' => $admin->id]); // unassigned

        $response = $this->actingAs($admin)->get(route('conversations.index', ['filter' => 'unassigned']));

        $this->assertCount(2, $response->viewData('conversations'));
    }

    public function test_show_marks_conversation_as_read(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'unread_count' => 5,
        ]);

        $this->actingAs($admin)->get(route('conversations.show', $conv));

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    public function test_agent_cannot_view_unassigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);  // unassigned

        $this->actingAs($agent)->get(route('conversations.show', $conv))->assertForbidden();
    }

    public function test_cross_account_show_is_forbidden(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $convOfB = Conversation::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA)->get(route('conversations.show', $convOfB))->assertForbidden();
    }

    public function test_reply_within_window_creates_outbound_message(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'last_inbound_at' => now()->subHours(2),  // window open
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.outbound_1']]], 200)]);

        $this->actingAs($admin)
            ->post(route('conversations.reply', $conv), ['body' => 'Thanks for reaching out!'])
            ->assertRedirect(route('conversations.show', $conv));

        $msg = ConversationMessage::where('conversation_id', $conv->id)
            ->where('direction', 'outbound')
            ->first();
        $this->assertNotNull($msg);
        $this->assertSame('Thanks for reaching out!', $msg->body);
        $this->assertSame('wamid.outbound_1', $msg->whatsapp_message_id);
        $this->assertSame($admin->id, $msg->sent_by_user_id);
    }

    public function test_reply_outside_window_without_template_is_blocked(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->windowClosed()->create(['user_id' => $admin->id]);

        Http::fake();

        $this->actingAs($admin)
            ->post(route('conversations.reply', $conv), ['body' => 'Hi again'])
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_agent_cannot_reply_to_unassigned_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        Http::fake();

        $this->actingAs($agent)
            ->post(route('conversations.reply', $conv), ['body' => 'hi'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_user_without_chat_permissions_cannot_access_inbox(): void
    {
        // No spatie permissions = no role with conversations.view_*
        $u = User::factory()->create();

        $this->actingAs($u)->get(route('conversations.index'))->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Assignment workflow (Phase 14)
    // ──────────────────────────────────────────────────────────────────────

    public function test_admin_can_assign_conversation_to_agent(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('conversations.assign', $conv), ['user_id' => $agent->id])
            ->assertRedirect(route('conversations.show', $conv))
            ->assertSessionHas('success');

        $this->assertSame($agent->id, $conv->fresh()->assigned_to_user_id);
    }

    public function test_admin_can_unassign_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $conv = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('conversations.assign', $conv), ['user_id' => null])
            ->assertRedirect();

        $this->assertNull($conv->fresh()->assigned_to_user_id);
    }

    public function test_agent_cannot_assign_conversations(): void
    {
        // 'agent' role lacks conversations.assign — only admin/manager get it.
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $otherAgent = $this->makeUser('agent', 'other-agent@example.com');
        $conv = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);

        $this->actingAs($agent)
            ->post(route('conversations.assign', $conv), ['user_id' => $otherAgent->id])
            ->assertForbidden();

        // Original assignment unchanged.
        $this->assertSame($agent->id, $conv->fresh()->assigned_to_user_id);
    }

    public function test_assigning_to_deactivated_user_is_blocked(): void
    {
        $admin = $this->makeUser('admin');
        $deactivated = $this->makeUser('agent');
        $deactivated->update(['is_active' => false]);
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('conversations.assign', $conv), ['user_id' => $deactivated->id])
            ->assertStatus(422);

        $this->assertNull($conv->fresh()->assigned_to_user_id);
    }

    public function test_self_assign_works_for_managers(): void
    {
        // Common pattern: a manager browsing the unassigned pool clicks "take this one".
        $manager = $this->makeUser('manager');
        $conv = Conversation::factory()->create(['user_id' => $manager->id]);

        $this->actingAs($manager)
            ->post(route('conversations.assign', $conv), ['user_id' => $manager->id])
            ->assertRedirect();

        $this->assertSame($manager->id, $conv->fresh()->assigned_to_user_id);
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
