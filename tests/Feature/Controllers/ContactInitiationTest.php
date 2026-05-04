<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_startChat_creates_conversation_for_new_contact(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->assertSame(0, Conversation::count());

        $response = $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact));

        $response->assertRedirect();
        $this->assertSame(1, Conversation::count());

        $conv = Conversation::first();
        $this->assertSame($contact->id, $conv->contact_id);
        $this->assertSame($instance->id, $conv->whatsapp_instance_id);
        $response->assertRedirect(route('conversations.show', $conv));
    }

    public function test_startChat_reuses_existing_conversation(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $existing = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('conversations.show', $existing));

        $this->assertSame(1, Conversation::count(), 'Must not create a duplicate conversation');
    }

    public function test_startChat_requires_conversations_reply_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);  // no role assigned
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        WhatsAppInstance::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('contacts.startChat', $contact))
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    public function test_startChat_blocks_cross_account_contact(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $contactOfB = Contact::factory()->create(['user_id' => $userB->id]);
        WhatsAppInstance::factory()->create(['user_id' => $userA->id]);

        $this->actingAs($userA)
            ->post(route('contacts.startChat', $contactOfB))
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    public function test_startCall_blocked_when_contact_has_no_engagement(): void
    {
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        \Illuminate\Support\Facades\Http::fake();

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(0, \App\Models\CallLog::count());
    }

    public function test_startCall_allowed_when_contact_messaged_within_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.engagement',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(5),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response([
                'calls' => [['id' => 'wacid.contact_initiated']],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('conversations.show', $conv))
            ->assertSessionHas('success');

        $this->assertSame(1, \App\Models\CallLog::count());
        $call = \App\Models\CallLog::first();
        $this->assertSame('outbound', $call->direction);
        $this->assertSame($admin->id, $call->placed_by_user_id);
        $this->assertSame('wacid.contact_initiated', $call->meta_call_id);
    }

    public function test_startCall_allowed_when_contact_called_within_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'created_at' => now()->subDays(10),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response([
                'calls' => [['id' => 'wacid.from_call']],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, \App\Models\CallLog::count(), 'inbound + new outbound');
    }

    public function test_startCall_engagement_threshold_is_30_days(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.too_old',
            'type' => 'text',
            'body' => 'old',
            'received_at' => now()->subDays(31),
        ]);

        \Illuminate\Support\Facades\Http::fake();

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\CallLog::count());
    }

    public function test_startCall_requires_conversations_call_permission(): void
    {
        $agent = $this->makeUser('agent');
        WhatsAppInstance::factory()->create(['user_id' => $agent->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('contacts.startCall', $contact))
            ->assertForbidden();
    }

    public function test_startCall_blocks_cross_account_contact(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        WhatsAppInstance::factory()->create(['user_id' => $userA->id, 'status' => 'CONNECTED']);
        $contactOfB = Contact::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA)
            ->post(route('contacts.startCall', $contactOfB))
            ->assertForbidden();
    }

    public function test_startChat_with_multiple_active_instances_picks_via_instance_id(): void
    {
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->count(2)->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        // POST without instance_id → resolveInstance returns null because firstWhere('id', 0) misses,
        // controller flashes "Pick which WhatsApp number..." and redirects back.
        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Conversation::count(), 'No conversation when picker not used');

        // Pick the second instance via the request body — modal does this on confirm.
        $instance2 = WhatsAppInstance::where('user_id', $admin->id)->skip(1)->first();
        $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact), ['instance_id' => $instance2->id])
            ->assertRedirect();

        $conv = Conversation::first();
        $this->assertNotNull($conv);
        $this->assertSame($instance2->id, $conv->whatsapp_instance_id);
    }

    public function test_startChat_with_zero_connected_instances_flashes_setup_error(): void
    {
        $admin = $this->makeUser('admin');
        // DISCONNECTED instance — resolveInstance filters it out, treating user as instance-less.
        WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'DISCONNECTED',
        ]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Conversation::count());
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
