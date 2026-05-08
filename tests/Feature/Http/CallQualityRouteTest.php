<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallQualityRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_quality_endpoint_persists_payload_with_computed_mos(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $payload = [
            'avg_jitter_ms' => 18.5,
            'avg_packet_loss_pct' => 0.3,
            'avg_rtt_ms' => 145,
            'samples_captured' => 18,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $response = $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $payload);

        $response->assertOk()->assertJsonStructure(['mos']);

        $fresh = $call->fresh();
        $this->assertNotNull($fresh->quality_metrics);
        $this->assertSame(18.5, $fresh->quality_metrics['avg_jitter_ms']);
        $this->assertSame(0.3, $fresh->quality_metrics['avg_packet_loss_pct']);
        $this->assertSame(145, $fresh->quality_metrics['avg_rtt_ms']);
        $this->assertSame(18, $fresh->quality_metrics['samples_captured']);
        $this->assertSame('host', $fresh->quality_metrics['ice_candidate_type']);
        $this->assertSame('opus', $fresh->quality_metrics['codec']);
        $this->assertGreaterThanOrEqual(1.0, $fresh->quality_metrics['mos']);
        $this->assertLessThanOrEqual(5.0, $fresh->quality_metrics['mos']);
    }

    public function test_outbound_owner_passes_inbound_owner_passes_other_user_403(): void
    {
        $owner = $this->makeAgent();
        $stranger = $this->makeAgent();

        $outbound = $this->makeOutboundCall($owner);

        $valid = [
            'avg_jitter_ms' => 10.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($owner)
            ->postJson(route('calls.quality', $outbound), $valid)
            ->assertOk();

        $this->actingAs($stranger)
            ->postJson(route('calls.quality', $outbound), $valid)
            ->assertForbidden();

        $inbound = $this->makeInboundCall(assignedTo: $owner);

        $this->actingAs($owner)
            ->postJson(route('calls.quality', $inbound), $valid)
            ->assertOk();

        $this->actingAs($stranger)
            ->postJson(route('calls.quality', $inbound), $valid)
            ->assertForbidden();
    }

    public function test_quality_endpoint_rejects_invalid_payload(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $invalid = [
            'avg_jitter_ms' => -5.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $invalid)
            ->assertStatus(422);
    }

    public function test_quality_endpoint_rejects_unknown_ice_candidate_type(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $invalid = [
            'avg_jitter_ms' => 10.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 10,
            'ice_candidate_type' => 'made_up_value',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $invalid)
            ->assertStatus(422);
    }

    public function test_quality_endpoint_overwrites_previous_post(): void
    {
        $agent = $this->makeAgent();
        $call = $this->makeOutboundCall($agent);

        $first = [
            'avg_jitter_ms' => 50.0,
            'avg_packet_loss_pct' => 5.0,
            'avg_rtt_ms' => 300,
            'samples_captured' => 10,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $first)
            ->assertOk();

        $second = [
            'avg_jitter_ms' => 5.0,
            'avg_packet_loss_pct' => 0.0,
            'avg_rtt_ms' => 50,
            'samples_captured' => 20,
            'ice_candidate_type' => 'host',
            'codec' => 'opus',
        ];

        $this->actingAs($agent)
            ->postJson(route('calls.quality', $call), $second)
            ->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(5.0, $fresh->quality_metrics['avg_jitter_ms']);
        $this->assertSame(20, $fresh->quality_metrics['samples_captured']);
    }

    private function makeAgent(): User
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $agent->assignRole(User::ROLE_AGENT);
        $agent->givePermissionTo('conversations.reply');
        return $agent;
    }

    private function makeOutboundCall(User $owner): CallLog
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $owner->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'outbound',
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'sess_'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'placed_by_user_id' => $owner->id,
            'from_phone' => '+2348100000000',
            'to_phone' => $contact->phone,
        ]);
    }

    private function makeInboundCall(User $assignedTo): CallLog
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $admin->assignRole(User::ROLE_ADMIN);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $contact = Contact::factory()->create([
            'user_id' => $admin->id,
            'phone' => '23480'.fake()->unique()->numerify('########'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $admin->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'assigned_to_user_id' => $assignedTo->id,
            'unread_count' => 0,
        ]);

        return CallLog::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_instance_id' => $instance->id,
            'direction' => 'inbound',
            'provider' => CallLog::PROVIDER_META_WHATSAPP,
            'meta_call_id' => 'wacid_'.fake()->unique()->numerify('########'),
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'from_phone' => $contact->phone,
            'to_phone' => '+2348100000000',
        ]);
    }
}
