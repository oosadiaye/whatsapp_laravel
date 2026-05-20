<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot diagnostic for queue worker health.
 *
 * Background: BlastIQ has hit "campaign queued forever" multiple times in
 * production, with different root causes each time — worker not running,
 * worker watching wrong queues, stale worker from before a deploy, jobs
 * failing silently to failed_jobs, etc. Each incident burned ~30 minutes
 * of "is the worker even running?" guessing.
 *
 * This command answers every common question at once:
 *   - which queue connection is configured?
 *   - how many jobs are sitting in each queue right now?
 *   - how many recent jobs have failed?
 *   - how many campaigns are stuck (QUEUED/RUNNING but no recent
 *     MessageLog activity)?
 *   - copy-pasteable recovery commands for the most common fixes
 *
 * Operator runbook:
 *   php artisan queue:doctor
 *
 * Designed to run on a laggy SSH connection — every line of output is
 * standalone and the whole report fits in one terminal screen.
 */
class QueueDoctor extends Command
{
    protected $signature = 'queue:doctor';

    protected $description = 'Diagnose queue worker health and stuck campaigns. Run this when "jobs are queued but nothing happens."';

    /**
     * Queues this app uses. Kept in sync with the ->onQueue() calls
     * inside app/Jobs/*. If you add a new queue name, add it here so
     * the doctor reports its depth.
     */
    private const KNOWN_QUEUES = ['default', 'messages', 'imports'];

    /**
     * "Stuck" threshold: a campaign in QUEUED/RUNNING for more than this
     * with zero MessageLog activity is almost certainly waiting on a
     * worker that isn't consuming the right queue. Matches the yellow
     * banner in resources/views/campaigns/show.blade.php.
     */
    private const STUCK_MINUTES = 5;

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=cyan>━━ BlastIQ queue doctor ━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        $hasProblem = false;

        // ── 1. Queue connection ────────────────────────────────────────
        $connection = config('queue.default');
        $this->info("Queue connection: <fg=white>{$connection}</>");

        if ($connection === 'sync') {
            $this->warn('  → "sync" runs jobs inline in the dispatching process. Background processing is disabled.');
            $hasProblem = true;
        }
        if ($connection === 'database' && ! Schema::hasTable('jobs')) {
            $this->error('  ✗ jobs table missing — run: php artisan migrate --force');
            $hasProblem = true;
        }
        $this->newLine();

        // ── 2. Pending jobs by queue ──────────────────────────────────
        // The jobs-table inspection is independent of the configured
        // connection: even if QUEUE_CONNECTION=sync (which runs jobs
        // inline and ignores the jobs table), the table may still hold
        // rows from previous runs or other connection configs. Counting
        // what's there is always informative.
        $this->info('Pending jobs by queue:');
        $totalPending = 0;
        if (Schema::hasTable('jobs')) {
            foreach (self::KNOWN_QUEUES as $queue) {
                $count = DB::table('jobs')->where('queue', $queue)->count();
                $totalPending += $count;
                $marker = $count > 0 ? '<fg=yellow>●</>' : '○';
                $this->line(sprintf('  %s %-12s %d', $marker, $queue, $count));
            }

            // Catch jobs on queues we don't know about — usually a hint
            // that someone added a new ->onQueue() call without updating
            // the supervisor config.
            $unknown = DB::table('jobs')
                ->whereNotIn('queue', self::KNOWN_QUEUES)
                ->select('queue', DB::raw('COUNT(*) as n'))
                ->groupBy('queue')
                ->get();
            foreach ($unknown as $row) {
                // Use line() with <comment> tag rather than warn() — warn()
                // writes to a stderr-style buffer that Artisan::call's
                // capture sometimes misses, breaking automated tests.
                $this->line(sprintf('  <comment>⚠ %-12s %d   (NOT in worker --queue= list — add it to deploy/supervisor-worker.conf)</>', $row->queue, $row->n));
                $hasProblem = true;
            }
        } else {
            $this->line('  (jobs table missing — run: php artisan migrate --force)');
        }
        $this->newLine();

        // ── 3. Failed jobs ─────────────────────────────────────────────
        if (Schema::hasTable('failed_jobs')) {
            $failedTotal = DB::table('failed_jobs')->count();
            $failedRecent = DB::table('failed_jobs')
                ->where('failed_at', '>=', Carbon::now()->subHours(24))
                ->count();
            $colour = $failedRecent > 0 ? 'yellow' : 'green';
            $this->line("Failed jobs: <fg={$colour}>{$failedRecent} in last 24h</>, {$failedTotal} total");
            if ($failedRecent > 0) {
                $this->line('  → Inspect: <fg=white>php artisan queue:failed</>');
                $this->line('  → Retry  : <fg=white>php artisan queue:retry all</>');
                $hasProblem = true;
            }
        }
        $this->newLine();

        // ── 4. Stuck campaigns ─────────────────────────────────────────
        // Same heuristic as the in-app warning banner: QUEUED/RUNNING
        // campaign older than N minutes with sent_count = 0.
        if (Schema::hasTable('campaigns')) {
            $threshold = Carbon::now()->subMinutes(self::STUCK_MINUTES);
            $stuck = Campaign::whereIn('status', ['QUEUED', 'RUNNING'])
                ->where('created_at', '<', $threshold)
                ->where(function ($q) {
                    $q->whereNull('sent_count')->orWhere('sent_count', 0);
                })
                ->count();
            if ($stuck > 0) {
                $this->line("<comment>Stuck campaigns: {$stuck} have been QUEUED/RUNNING for >".self::STUCK_MINUTES.'min with 0 sends.</>');
                $this->line('  → A worker not consuming the "messages" queue is the usual cause.');
                $hasProblem = true;
            } else {
                $this->info('Stuck campaigns: 0');
            }
        }
        $this->newLine();

        // ── 5. Recovery hints ──────────────────────────────────────────
        if ($hasProblem || $totalPending > 0) {
            $this->line('<fg=cyan>Common fixes</>');
            $this->line('  Restart supervisor worker:');
            $this->line('    <fg=white>sudo supervisorctl restart blastiq-worker:*</>');
            $this->line('  Run a single worker iteration in foreground (shows live errors):');
            $this->line('    <fg=white>php -d opcache.enable=0 artisan queue:work --queue=default,messages,imports --once --tries=1</>');
            $this->line('  First-time worker setup:');
            $this->line('    <fg=white>sudo bash deploy/install-supervisor.sh</>');
            $this->line('  Confirm worker is watching the right queues:');
            $this->line('    <fg=white>ps -ef | grep "queue:work" | grep -v grep</>');
            $this->line('    expect: <fg=white>--queue=default,messages,imports</>');
            $this->newLine();
        } else {
            $this->info('All checks passed. Worker should be consuming normally.');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
