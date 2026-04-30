<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sees_all_calls_in_account(): void
    {
        $admin = $this->makeUser('admin');
        $convs = Conversation::factory()->count(3)->create(['user_id' => $admin->id]);
        foreach ($convs as $c) {
            CallLog::factory()->create([
                'conversation_id' => $c->id,
                'contact_id' => $c->contact_id,
                'whatsapp_instance_id' => $c->whatsapp_instance_id,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('calls.index'));

        $response->assertOk();
        $this->assertCount(3, $response->viewData('calls'));
    }

    public function test_admin_does_not_see_calls_from_other_accounts(): void
    {
        $admin = $this->makeUser('admin');
        $other = $this->makeUser('admin', 'other@example.com');

        $myConv = Conversation::factory()->create(['user_id' => $admin->id]);
        CallLog::factory()->create([
            'conversation_id' => $myConv->id,
            'contact_id' => $myConv->contact_id,
            'whatsapp_instance_id' => $myConv->whatsapp_instance_id,
        ]);

        $otherConv = Conversation::factory()->create(['user_id' => $other->id]);
        CallLog::factory()->count(5)->create([
            'conversation_id' => $otherConv->id,
            'contact_id' => $otherConv->contact_id,
            'whatsapp_instance_id' => $otherConv->whatsapp_instance_id,
        ]);

        $this->actingAs($admin)->get(route('calls.index'))
            ->assertViewHas('calls', fn ($calls) => count($calls) === 1);
    }

    public function test_agent_sees_only_calls_in_assigned_conversations(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');

        $assigned = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);
        $unassigned = Conversation::factory()->count(2)->create(['user_id' => $admin->id]);

        CallLog::factory()->create([
            'conversation_id' => $assigned->id,
            'contact_id' => $assigned->contact_id,
            'whatsapp_instance_id' => $assigned->whatsapp_instance_id,
        ]);
        foreach ($unassigned as $c) {
            CallLog::factory()->create([
                'conversation_id' => $c->id,
                'contact_id' => $c->contact_id,
                'whatsapp_instance_id' => $c->whatsapp_instance_id,
            ]);
        }

        $response = $this->actingAs($agent)->get(route('calls.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('calls'));
    }

    public function test_user_with_no_chat_permissions_gets_403(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('calls.index'))->assertForbidden();
    }

    public function test_filter_by_direction_works(): void
    {
        $admin = $this->makeUser('admin');
        $conv = Conversation::factory()->create(['user_id' => $admin->id]);
        CallLog::factory()->count(2)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
            'direction' => 'inbound',
        ]);
        CallLog::factory()->outbound($admin)->count(3)->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $conv->whatsapp_instance_id,
        ]);

        $this->actingAs($admin)->get(route('calls.index', ['direction' => 'outbound']))
            ->assertViewHas('calls', fn ($calls) => count($calls) === 3);
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
