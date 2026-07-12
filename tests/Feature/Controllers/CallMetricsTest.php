<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\CallLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /calls observability tiles: answer rate, time-to-answer, MOS, and the
 * today's-outcomes breakdown — all today-scoped and visibility-scoped.
 */
class CallMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_index_computes_answer_rate_time_to_answer_and_mos(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin'); // has conversations.view_all

        // Answered: connected 5s after start, MOS 4.2.
        CallLog::factory()->create([
            'status' => CallLog::STATUS_ENDED,
            'started_at' => now()->subMinutes(3),
            'connected_at' => now()->subMinutes(3)->addSeconds(5),
            'duration_seconds' => 90,
            'quality_metrics' => ['mos' => 4.2, 'avg_jitter_ms' => 10, 'avg_packet_loss_pct' => 0.5, 'avg_rtt_ms' => 40, 'samples_captured' => 5, 'ice_candidate_type' => 'host', 'codec' => 'opus'],
        ]);
        // Missed.
        CallLog::factory()->missed()->create();

        $stats = $this->actingAs($admin)
            ->get(route('calls.index'))
            ->assertOk()
            ->viewData('stats');

        $this->assertSame(1, $stats['answered']);
        $this->assertSame(1, $stats['missed']);
        $this->assertSame(50, $stats['answerRate']);       // 1 answered / (1+1)
        $this->assertSame(5, $stats['avgTimeToAnswerSeconds']);
        $this->assertSame(4.2, $stats['avgMos']);
        $this->assertSame(1, $stats['statusBreakdown']['missed'] ?? 0);
    }

    public function test_metrics_are_null_safe_with_no_calls(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $stats = $this->actingAs($admin)
            ->get(route('calls.index'))
            ->assertOk()
            ->viewData('stats');

        $this->assertNull($stats['answerRate']);
        $this->assertNull($stats['avgTimeToAnswerSeconds']);
        $this->assertNull($stats['avgMos']);
    }
}
