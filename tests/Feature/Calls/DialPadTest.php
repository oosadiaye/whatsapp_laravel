<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Call Workspace dial pad (calls.dial): dialing a raw number or a contact
 * resolves — soft-delete-safe — to a contact + conversation owned by the dialer,
 * then hands off to the conversation page (?dial=1) which places the call. This
 * pins the resolution + the feature-scoped permission gate; the live PSTN/audio
 * leg reuses the already-covered placeOutbound path.
 */
class DialPadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function dialerWithInstance(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin'); // admin has calls.dial
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        return $user;
    }

    public function test_dialing_a_number_creates_contact_and_conversation_owned_by_the_dialer(): void
    {
        $user = $this->dialerWithInstance();

        $response = $this->actingAs($user)->post(route('calls.dial'), ['phone' => '08011112222']);

        $contact = Contact::where('user_id', $user->id)->first();
        $this->assertNotNull($contact, 'a contact should be created for the dialed number');

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conversation);
        // Owned by the dialer so they pass the outbound gate + can view the call.
        $this->assertSame($user->id, $conversation->assigned_to_user_id);

        // Hands off to the conversation view with the auto-dial flag.
        $response->assertRedirect(route('conversations.show', ['conversation' => $conversation, 'dial' => 1]));
    }

    public function test_dialing_the_same_number_twice_is_idempotent(): void
    {
        $user = $this->dialerWithInstance();

        $this->actingAs($user)->post(route('calls.dial'), ['phone' => '08011112222']);
        $this->actingAs($user)->post(route('calls.dial'), ['phone' => '08011112222']);

        // firstOrCreate(IncludingTrashed) — no duplicate contact/conversation.
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
    }

    public function test_dial_requires_the_calls_dial_permission(): void
    {
        // Authenticated but role-less → no calls.dial.
        $user = User::factory()->create(['is_active' => true]);
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        $this->actingAs($user)->post(route('calls.dial'), ['phone' => '08011112222'])
            ->assertForbidden();
    }

    public function test_dial_with_no_number_or_contact_is_rejected(): void
    {
        $user = $this->dialerWithInstance();

        $this->actingAs($user)->post(route('calls.dial'), [])
            ->assertSessionHas('error');

        $this->assertSame(0, Conversation::count());
    }

    public function test_dial_without_a_whatsapp_instance_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        // No WhatsAppInstance — calls are recorded against it, so dialing can't proceed.

        $this->actingAs($user)->post(route('calls.dial'), ['phone' => '08011112222'])
            ->assertSessionHas('error');

        $this->assertSame(0, Contact::count());
    }

    public function test_seeder_grants_calls_permissions_to_agent_and_admin(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole('agent');
        $this->assertTrue($agent->can('calls.view'));
        $this->assertTrue($agent->can('calls.dial'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('calls.dial'));
    }
}
