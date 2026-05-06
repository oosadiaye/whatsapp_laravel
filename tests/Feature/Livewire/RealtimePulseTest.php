<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\RealtimePulse;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealtimePulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_returns_empty_payload_for_unauthenticated_user(): void
    {
        Livewire::test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0)
            ->assertViewHas('unreadMessages', 0);
    }

    public function test_admin_sees_inflight_inbound_call_for_their_account(): void
    {
        $admin = $this->makeUser('admin');
        $instance = WhatsAppInstance::factory()->create([
            'user_id' => $admin->id,
            'status' => 'CONNECTED',
            'display_name' => 'Sales Line',
        ]);
        $conv = Conversation::factory()->create([
            'user_id' => $admin->id,
            'whatsapp_instance_id' => $instance->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $conv->id,
            'contact_id' => $conv->contact_id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'status' => 'ringing',
            'from_phone' => '+2348012345678',
        ]);

        Livewire::actingAs($admin)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', function ($calls) use ($conv) {
                return count($calls) === 1
                    && $calls[0]['conversation_id'] === $conv->id
                    && $calls[0]['phone'] === '+2348012345678'
                    && $calls[0]['instance_name'] === 'Sales Line'
                    && $calls[0]['status'] === 'ringing';
            });
    }

    public function test_admin_does_not_see_calls_from_other_accounts(): void
    {
        $userA = $this->makeUser('admin');
        $userB = $this->makeUser('admin', 'b@example.com');
        $instanceB = WhatsAppInstance::factory()->create([
            'user_id' => $userB->id,
            'status' => 'CONNECTED',
        ]);
        $convB = Conversation::factory()->create([
            'user_id' => $userB->id,
            'whatsapp_instance_id' => $instanceB->id,
        ]);
        CallLog::factory()->create([
            'conversation_id' => $convB->id,
            'contact_id' => $convB->contact_id,
            'whatsapp_instance_id' => $instanceB->id,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Livewire::actingAs($userA)
            ->test(RealtimePulse::class)
            ->assertViewHas('inflightCalls', fn ($calls) => count($calls) === 0);
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
