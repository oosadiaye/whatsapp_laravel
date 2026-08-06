<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\VoiceCallValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceCallValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_at_inbound_call_passes_all_checks(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true]);
        $agent->assignRole(User::ROLE_AGENT);
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);

        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'atSess'.uniqid(),
            'meta_call_id' => null,
            'direction' => CallLog::DIRECTION_INBOUND,
            'status' => CallLog::STATUS_ENDED,
            'connected_at' => now()->subMinutes(2),
            'duration_seconds' => 75,
            'cost_estimate_kobo' => 750,
            'answered_by_session_id' => 'browser-session-abc',
            'raw_event_log' => [
                ['event' => 'inbound_first', 'timestamp' => now()->toIso8601String(), 'payload' => []],
                ['event' => 'inprogress', 'timestamp' => now()->toIso8601String(), 'payload' => []],
                ['event' => 'completed', 'timestamp' => now()->toIso8601String(), 'payload' => []],
            ],
        ]);

        $report = (new VoiceCallValidator)->report($call);

        $this->assertNotEmpty($report);
        $this->assertEmpty(
            collect($report)->where('status', 'fail'),
            'healthy call should not fail any validation check',
        );
    }

    public function test_call_with_no_callbacks_fails_webhook_and_terminal_checks(): void
    {
        $call = CallLog::factory()->inFlight()->create([
            'raw_event_log' => null,
        ]);

        $report = collect((new VoiceCallValidator)->report($call));

        $this->assertNotEmpty($report->where('status', 'fail'));
        $this->assertTrue(
            $report->where('status', 'fail')->pluck('label')->contains('Webhook callbacks received'),
            'empty raw_event_log must fail the callbacks-received check',
        );
        $this->assertTrue(
            $report->where('status', 'fail')->pluck('label')->contains('Call reached a terminal state'),
        );
    }

    public function test_outbound_call_checks_placer_not_assignee(): void
    {
        $placer = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true]);
        $placer->assignRole(User::ROLE_AGENT);

        $call = CallLog::factory()->create([
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'atOut'.uniqid(),
            'meta_call_id' => null,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'status' => CallLog::STATUS_ENDED,
            'connected_at' => now()->subMinutes(1),
            'duration_seconds' => 30,
            'cost_estimate_kobo' => 300,
            'placed_by_user_id' => $placer->id,
            'raw_event_log' => [
                ['event' => 'ringing', 'timestamp' => now()->toIso8601String(), 'payload' => []],
                ['event' => 'completed', 'timestamp' => now()->toIso8601String(), 'payload' => []],
            ],
        ]);

        $report = collect((new VoiceCallValidator)->report($call));

        $this->assertEmpty($report->where('status', 'fail'));
        $this->assertTrue(
            $report->pluck('label')->contains('Outbound placed by an agent'),
            'outbound validation should check the placer, not an assignee',
        );
    }
}
