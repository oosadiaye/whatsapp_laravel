<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchLiveCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_terminal_call_prints_report_and_exits_zero(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'is_active' => true]);
        $agent->assignRole(User::ROLE_AGENT);
        $conversation = Conversation::factory()->create(['assigned_to_user_id' => $agent->id]);

        $call = CallLog::factory()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'provider' => CallLog::PROVIDER_AFRICAS_TALKING,
            'provider_session_id' => 'atWatch'.uniqid(),
            'meta_call_id' => null,
            'status' => CallLog::STATUS_ENDED,
            'connected_at' => now()->subMinutes(2),
            'duration_seconds' => 60,
            'cost_estimate_kobo' => 600,
            'answered_by_session_id' => 'browser-session-abc',
            'raw_event_log' => [
                ['event' => 'inbound_first', 'timestamp' => now()->toIso8601String(), 'payload' => []],
                ['event' => 'completed', 'timestamp' => now()->toIso8601String(), 'payload' => []],
            ],
        ]);

        $this->artisan('voice:watch', ['--call' => $call->id, '--timeout' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('Validation report')
            ->expectsOutputToContain('All checks passed');
    }

    public function test_broken_terminal_call_exits_one_with_failures(): void
    {
        // Terminal but with no recorded callbacks — the report must flag it.
        $call = CallLog::factory()->create([
            'raw_event_log' => null,
        ]);

        $this->artisan('voice:watch', ['--call' => $call->id, '--timeout' => 5])
            ->assertExitCode(1)
            ->expectsOutputToContain('validation check(s) failed');
    }

    public function test_times_out_when_call_never_reaches_terminal(): void
    {
        $call = CallLog::factory()->inFlight()->create();

        $this->artisan('voice:watch', ['--call' => $call->id, '--timeout' => 1])
            ->assertExitCode(1)
            ->expectsOutputToContain('Timed out');
    }

    public function test_times_out_when_session_never_appears(): void
    {
        $this->artisan('voice:watch', ['--session' => 'session-that-never-arrives', '--timeout' => 1])
            ->assertExitCode(1)
            ->expectsOutputToContain('Timed out');
    }
}
