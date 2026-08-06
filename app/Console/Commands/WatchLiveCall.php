<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Services\VoiceCallValidator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * `php artisan voice:watch` — watch a live call reach a terminal state and then
 * print the Phase 0 validation report (see docs/AFRICASTALKING-VERIFICATION.md).
 *
 * Use it while the operator makes a real call:
 *   - after `voice:verify-live --to=+234...` placed an outbound call (watch the
 *     session it printed), or
 *   - right before dialing the virtual number from a mobile (validates the
 *     inbound path: contact/conversation created, round-robin assignment, agent
 *     answered, duration/cost).
 *
 * With no --call/--session it watches the newest call from the last minute, or
 * the first call created while it runs.
 */
class WatchLiveCall extends Command
{
    protected $signature = 'voice:watch
        {--call= : call_logs.id to watch}
        {--session= : AT provider_session_id to watch}
        {--timeout=180 : max seconds to wait for a terminal state}
        {--interval=2 : poll interval in seconds}';

    protected $description = 'Watch a live call reach a terminal state, then print the Phase 0 validation report';

    public function handle(VoiceCallValidator $validator): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $timeout = max(1, (int) $this->option('timeout'));
        $deadline = now()->addSeconds($timeout);

        $startedAt = now();
        $call = $this->resolveTarget();
        $seenStatus = $call?->status;

        if ($call !== null) {
            $this->renderHeader($call);
        } else {
            $this->line('Watching for the next call (any direction)...');
        }

        while (true) {
            if ($call !== null) {
                $fresh = $call->fresh();

                if ($fresh->status !== $seenStatus) {
                    $this->line(sprintf('  [%s] %s → <options=bold>%s</>', now()->format('H:i:s'), $seenStatus ?? '—', $fresh->status));
                    $seenStatus = $fresh->status;
                }
                $call = $fresh;

                if (in_array($call->status, CallLog::STATUSES_TERMINAL, true)) {
                    return $this->printReport($call, $validator);
                }
            } else {
                $call = $this->buildWaitQuery($startedAt)->latest()->first();
                if ($call !== null) {
                    $seenStatus = $call->status;
                    $this->renderHeader($call);
                }
            }

            if (now()->gte($deadline)) {
                $this->newLine();
                $this->error('Timed out waiting for a terminal state.');

                return self::FAILURE;
            }

            sleep($interval);
        }
    }

    /**
     * Resolve the call to watch from --call / --session / "newest in the last
     * minute" (e.g. one just placed by `voice:verify-live --to=...`).
     */
    private function resolveTarget(): ?CallLog
    {
        $callId = (int) $this->option('call');

        if ($callId > 0) {
            return CallLog::find($callId);
        }

        if ((string) $this->option('session') !== '') {
            return CallLog::where('provider_session_id', (string) $this->option('session'))
                ->latest()
                ->first();
        }

        return CallLog::where('created_at', '>=', now()->subMinute())->latest()->first();
    }

    /**
     * Query used to detect a call appearing while the watcher runs (e.g. the
     * inbound first event lands after the operator dials the virtual number).
     */
    private function buildWaitQuery(\DateTimeInterface $startedAt): Builder
    {
        $query = CallLog::query()->where('created_at', '>=', $startedAt);

        if ((string) $this->option('session') !== '') {
            $query->where('provider_session_id', (string) $this->option('session'));
        }

        return $query;
    }

    private function renderHeader(CallLog $call): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Watching call #%s — %s %s (%s), currently <options=bold>%s</>',
            $call->id,
            $call->direction,
            $call->contact?->name ?? $call->from_phone ?? $call->to_phone ?? 'unknown',
            $call->provider,
            $call->status,
        ));
    }

    private function printReport(CallLog $call, VoiceCallValidator $validator): int
    {
        $this->newLine();
        $this->info(sprintf(
            '▸ Validation report — call #%s (%s, %s, session %s)',
            $call->id,
            $call->direction,
            $call->provider,
            $call->provider_session_id ?? $call->meta_call_id ?? '—',
        ));

        $failures = 0;
        foreach ($validator->report($call) as $row) {
            $mark = match ($row['status']) {
                'pass' => '<fg=green>✓</>',
                'fail' => '<fg=red>✗</>',
                default => '<fg=cyan>•</>',
            };
            $this->line(sprintf('  %s %-42s %s', $mark, $row['label'], $row['detail']));
            if ($row['status'] === 'fail') {
                $failures++;
            }
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("  {$failures} validation check(s) failed — see docs/AFRICASTALKING-VERIFICATION.md.");

            return self::FAILURE;
        }

        $this->info('  All checks passed. This validates persisted state only — confirm two-way audio and');
        $this->info('  that BOTH legs drop on hangup manually before relying on voice in production.');

        return self::SUCCESS;
    }
}
