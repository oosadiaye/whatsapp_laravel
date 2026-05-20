<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the queue:doctor command contract.
 *
 * This command is the operator's first-response tool for "campaigns
 * are queued and nothing's happening." If anyone refactors it to
 * silently miss a queue, or stops detecting stuck campaigns, the
 * problem becomes invisible again — exactly what this command was
 * built to prevent. These tests are the safety net.
 *
 * Uses Artisan::call + Artisan::output() rather than expectsOutputToContain
 * because the doctor uses Symfony Console formatting tags (<fg=...>) for
 * colour; expectsOutputToContain's line-matching gets confused by the
 * formatted output. Direct string inspection of the buffer is more
 * robust and gives clearer failure messages.
 */
class QueueDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_state_reports_all_passed(): void
    {
        // Production uses QUEUE_CONNECTION=database; the phpunit env
        // defaults to sync, which the doctor (correctly) flags as
        // "background processing is disabled." Override here so we're
        // testing the production-realistic clean-state path.
        config()->set('queue.default', 'database');

        $exit = Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertSame(0, $exit, 'queue:doctor should exit 0 on clean state');
        $this->assertStringContainsString('Queue connection:', $out);
        $this->assertStringContainsString('Pending jobs by queue:', $out);
        $this->assertStringContainsString('All checks passed', $out);
    }

    public function test_known_queues_are_each_listed_individually(): void
    {
        // The doctor reads from a hardcoded list of queue names that must
        // stay in sync with the ->onQueue() calls in app/Jobs/*. If anyone
        // adds a new ->onQueue('foo') without updating the constant, the
        // 'unknown queue' branch will fire. This test pins the canonical
        // list visible in the output.
        Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertStringContainsString('default', $out);
        $this->assertStringContainsString('messages', $out);
        $this->assertStringContainsString('imports', $out);
    }

    public function test_detects_jobs_on_unknown_queues(): void
    {
        // Simulate someone dispatching a job to a queue name not in the
        // supervisor --queue= list. The doctor must flag it so the
        // operator knows to update deploy/supervisor-worker.conf.
        DB::table('jobs')->insert([
            'queue' => 'orphan_queue',
            'payload' => json_encode(['stub']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertStringContainsString('orphan_queue', $out);
        $this->assertStringContainsString('NOT in worker', $out);
    }

    public function test_detects_truly_stuck_campaign_with_no_message_logs(): void
    {
        // "Truly stuck" = QUEUED/RUNNING > 5 min, sent_count = 0, AND
        // no MessageLog rows exist for it. Last condition is what
        // distinguishes a fan-out-never-ran failure from a campaign
        // that's just rate-limit-draining slowly.
        $admin = User::factory()->create(['is_active' => true]);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Stuck since long ago',
            'message' => 'hi',
            'status' => 'QUEUED',
            'sent_count' => 0,
        ]);
        Campaign::withoutTimestamps(function () use ($campaign): void {
            $campaign->forceFill([
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ])->save();
        });

        Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertStringContainsString('Stuck campaigns:', $out);
        $this->assertStringContainsString('1 have been QUEUED', $out);
    }

    public function test_recent_campaign_with_zero_sends_is_not_yet_stuck(): void
    {
        // A campaign created seconds ago is *expected* to have sent_count=0.
        // Only campaigns past the >5min threshold qualify as "stuck."
        $admin = User::factory()->create(['is_active' => true]);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Just dispatched',
            'message' => 'hi',
            'status' => 'QUEUED',
            'sent_count' => 0,
        ]);

        Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertStringContainsString('Stuck campaigns: 0', $out);
    }

    public function test_rate_limited_campaign_with_pending_message_logs_is_not_flagged_stuck(): void
    {
        // A campaign that has been QUEUED > 5 min with sent_count=0 BUT
        // already has MessageLog rows (= fan-out happened, sends are
        // throttling out) must NOT be flagged "stuck". It's working
        // correctly, just rate-limited. The doctor should instead
        // surface it as an informational "rate-limited" line.
        $admin = User::factory()->create(['is_active' => true]);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Throttling out',
            'message' => 'hi',
            'status' => 'RUNNING',
            'sent_count' => 0,
        ]);
        Campaign::withoutTimestamps(function () use ($campaign): void {
            $campaign->forceFill([
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ])->save();
        });

        // Create at least one MessageLog row so whereHas('messageLogs')
        // returns true — same shape CampaignBatchDispatch would create.
        \App\Models\MessageLog::create([
            'campaign_id' => $campaign->id,
            'phone' => '+2348012345678',
            'status' => 'PENDING',
        ]);

        Artisan::call('queue:doctor');
        $out = Artisan::output();

        $this->assertStringContainsString('Stuck campaigns: 0', $out);
        $this->assertStringContainsString('Rate-limited campaigns: 1', $out);
    }
}
