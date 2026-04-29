<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "marketing safeguard" — block campaign creation when the picked
 * template is in a state Meta will reject (PENDING / REJECTED).
 */
class CampaignTemplateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_pending_template_is_rejected_with_clear_error(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Test']);

        $pendingTemplate = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'status' => MessageTemplate::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'My campaign',
            'message' => 'Body text',
            'instance_id' => $instance->id,
            'message_template_id' => $pendingTemplate->id,
            'groups' => [$group->id],
        ]);

        $response->assertSessionHasErrors('message_template_id');
        $this->assertSame(0, Campaign::count());

        $errors = session('errors');
        $this->assertStringContainsString('PENDING', $errors->first('message_template_id'));
    }

    public function test_rejected_template_is_blocked(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Test']);

        $rejected = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'status' => MessageTemplate::STATUS_REJECTED,
        ]);

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'C',
            'message' => 'Body',
            'instance_id' => $instance->id,
            'message_template_id' => $rejected->id,
            'groups' => [$group->id],
        ])->assertSessionHasErrors('message_template_id');

        $this->assertSame(0, Campaign::count());
    }

    public function test_approved_template_passes_validation(): void
    {
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Test']);

        $approved = MessageTemplate::factory()->remote()->create([
            'user_id' => $user->id,
            'whatsapp_instance_id' => $instance->id,
            'status' => MessageTemplate::STATUS_APPROVED,
        ]);

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'C',
            'message' => 'Body',
            'instance_id' => $instance->id,
            'message_template_id' => $approved->id,
            'groups' => [$group->id],
        ])->assertRedirect();  // 302 to campaigns.show — successful creation

        $this->assertSame(1, Campaign::count());
        $this->assertSame($approved->id, Campaign::first()->message_template_id);
    }

    public function test_local_template_is_allowed_through(): void
    {
        // Local templates have status=LOCAL but are still valid to use as
        // pre-fills for the message body. They don't go through Meta approval.
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Test']);

        $local = MessageTemplate::factory()->create([
            'user_id' => $user->id,
            'status' => MessageTemplate::STATUS_LOCAL,
        ]);

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'C',
            'message' => 'Body',
            'instance_id' => $instance->id,
            'message_template_id' => $local->id,
            'groups' => [$group->id],
        ])->assertRedirect();

        $this->assertSame(1, Campaign::count());
    }

    public function test_no_template_passes_with_no_error_only_a_runtime_warning(): void
    {
        // Compose-from-scratch is allowed (legitimate for service replies),
        // we just show a banner in the UI. Don't block at validation level.
        $user = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $user->id]);
        $group = ContactGroup::create(['user_id' => $user->id, 'name' => 'Test']);

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'C',
            'message' => 'Hi there',
            'instance_id' => $instance->id,
            'groups' => [$group->id],
        ])->assertRedirect()
          ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, Campaign::count());
    }
}
