<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoAwayAgents extends Command
{
    protected $signature = 'agents:auto-away {--threshold=5 : Minutes of inactivity before marking away}';

    protected $description = 'Mark agents as away when last_seen_at exceeds the inactivity threshold';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));

        $updated = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('presence_status', '!=', User::PRESENCE_AWAY)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($threshold));
            })
            ->update([
                'presence_status' => User::PRESENCE_AWAY,
                'presence_status_set_at' => now(),
            ]);

        if ($updated > 0) {
            Log::info("Auto-away: marked {$updated} agent(s) as away (threshold: {$threshold}min).");
        }

        $this->info("Marked {$updated} agent(s) as away.");

        return self::SUCCESS;
    }
}
