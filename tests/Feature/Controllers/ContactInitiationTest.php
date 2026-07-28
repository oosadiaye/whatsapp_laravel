<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // startCall places a DIRECT Africa's Talking PSTN call — no Meta Cloud
        // Calling, no engagement gate. This matches the Call Workspace dial pad
        // and CallController::placeOutbound, which also let an operator dial any
        // number without an "engaged within 30 days" precondition (that gate was
        // a Meta-Cloud-Calling compliance requirement, and AT calling isn't
        // subject to it). Configure AT so placeCall() succeeds and fake the /call
        // endpoint by default; the failure-path test overrides this fake.
        Setting::set('africastalking_username', 'sandbox');
        Setting::set('africastalking_api_key', Crypt::encryptString('atsk_test_key'));
        Setting::set('africastalking_virtual_number', '+2348100000000');
        Setting::set('default_country_code', '234');
    }

    /**
     * Fake a successful AT /call response. Registered per-test (not in setUp)
     * because Http::fake() appends stubs and the FIRST registered match wins —
     * a setUp stub would shadow a failure-path test's override.
     */
    private function fakeQueuedAtCall(string $sessionId = 'sess_contact_dial'): void
    {
        Http::fake([
            'voice.africastalking.com/call' => Http::response([
                'entries' => [['sessionId' => $sessionId, 'status' => 'Queued']],
            ], 201),
        ]);
    }

    // ---- startCall: direct Africa's Talking dial -----------------------------

    public function test_startCall_places_an_at_call_and_logs_it(): void
    {
        $this->fakeQueuedAtCall();
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        $this->actingAs($admin)
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, CallLog::count());
        $call = CallLog::first();
        $this->assertSame('outbound', $call->direction);
        $this->assertSame(CallLog::PROVIDER_AFRICAS_TALKING, $call->provider);
        $this->assertSame(CallLog::STATUS_INITIATED, $call->status);
        $this->assertSame('sess_contact_dial', $call->provider_session_id);
        $this->assertSame($admin->id, $call->placed_by_user_id);
    }

    public function test_startCall_assigns_conversation_to_the_dialer_when_unassigned(): void
    {
        $this->fakeQueuedAtCall();
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        $this->actingAs($admin)->post(route('contacts.startCall', $contact));

        $conv = Conversation::first();
        $this->assertNotNull($conv);
        $this->assertSame($admin->id, $conv->assigned_to_user_id);
    }

    public function test_startCall_flashes_setup_error_when_no_instance_configured(): void
    {
        $admin = $this->makeUser('admin');
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, CallLog::count());
    }

    public function test_startCall_flashes_error_and_logs_nothing_when_voice_provider_fails(): void
    {
        Http::fake(['voice.africastalking.com/call' => Http::response(['error' => 'down'], 500)]);

        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, CallLog::count());
    }

    public function test_startCall_flashes_error_for_a_contact_with_an_unnormalisable_phone(): void
    {
        // A bad import can leave a phone that can't be normalised to E.164;
        // placeCall throws \InvalidArgumentException, which startCall must catch
        // and surface as an error flash rather than a 500.
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create(['user_id' => $admin->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $admin->id, 'phone' => 'not-a-number']);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startCall', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, CallLog::count());
    }

    public function test_startCall_requires_conversations_call_permission(): void
    {
        // agent/manager/admin/super_admin all grant conversations.call by
        // default, so use a role-less user to isolate the policy gate.
        $user = User::factory()->create(['is_active' => true]);
        WhatsAppInstance::factory()->create(['user_id' => $user->id, 'status' => 'CONNECTED']);
        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('contacts.startCall', $contact))
            ->assertForbidden();

        $this->assertSame(0, CallLog::count());
    }

    public function test_startCall_is_not_blocked_by_contact_ownership_in_single_tenant(): void
    {
        // Single-tenant: contacts are shared. An admin can dial a contact owned
        // by another user — the old per-user ownership 403 is gone (route
        // permission gating still applies).
        $this->fakeQueuedAtCall();
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        WhatsAppInstance::factory()->create(['user_id' => $userA->id, 'status' => 'CONNECTED']);
        $contactOfB = Contact::factory()->create([
            'user_id' => $userB->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);

        $response = $this->actingAs($userA)
            ->post(route('contacts.startCall', $contactOfB));

        $this->assertFalse($response->isForbidden(), 'ownership must not 403 in single-tenant');
        $response->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, CallLog::count());
    }

    // ---- startChat: open a WhatsApp thread (no message sent) -----------------

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

    public function test_startChat_is_not_blocked_by_contact_ownership_in_single_tenant(): void
    {
        // Single-tenant: contacts are shared. An admin can start a chat for a
        // contact owned by another user — the old per-user ownership 403 is
        // gone (route permission gating still applies).
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $contactOfB = Contact::factory()->create(['user_id' => $userB->id]);
        WhatsAppInstance::factory()->create(['user_id' => $userA->id]);

        $this->actingAs($userA)
            ->post(route('contacts.startChat', $contactOfB))
            ->assertRedirect(); // not 403

        $this->assertSame(1, Conversation::count());
    }

    public function test_startChat_uses_the_primary_instance_without_a_picker(): void
    {
        // Single-instance app: no send-time picker. startChat resolves to the
        // one primary WhatsApp number and creates the conversation directly.
        $admin = $this->makeUser('admin');
        WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect();

        $conv = Conversation::first();
        $this->assertNotNull($conv);
        $this->assertSame(WhatsAppInstance::primary()->id, $conv->whatsapp_instance_id);
    }

    public function test_startChat_with_no_instance_configured_flashes_setup_error(): void
    {
        // Single-instance app: when WhatsApp has not been configured in Settings
        // (no instance row at all), startChat flashes a setup error instead of
        // creating an orphan conversation.
        $admin = $this->makeUser('admin');
        $contact = Contact::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('contacts.index'))
            ->post(route('contacts.startChat', $contact))
            ->assertRedirect(route('contacts.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Conversation::count());
    }

    public function test_contact_index_exposes_is_engaged_flag_for_each_contact(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
        ]);

        // Engaged contact — has a recent inbound message.
        $engaged = Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Engaged']);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'contact_id' => $engaged->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        \App\Models\ConversationMessage::create([
            'conversation_id' => $conv->id,
            'direction' => 'inbound',
            'whatsapp_message_id' => 'wamid.eager',
            'type' => 'text',
            'body' => 'hi',
            'received_at' => now()->subDays(3),
        ]);

        // Cold contact — no activity.
        Contact::factory()->create(['user_id' => $admin->id, 'name' => 'Cold']);

        $response = $this->actingAs($admin)->get(route('contacts.index'));

        $response->assertOk();
        $contacts = $response->viewData('contacts');

        $byName = $contacts->keyBy('name');
        $this->assertTrue((bool) $byName['Engaged']->is_engaged);
        $this->assertFalse((bool) $byName['Cold']->is_engaged);
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
