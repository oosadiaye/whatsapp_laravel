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
