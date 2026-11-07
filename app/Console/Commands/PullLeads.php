<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullLeadProfileJob;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Console\Command;

class PullLeads extends Command
{
    protected $signature = 'leads:pull {--profile= : Pull only this lead profile id, even if it was pulled recently}';

    protected $description = 'Queue a pull for every active lead profile that is due.';

    public function handle(): int
    {
        if (LeadConfig::killSwitchOn()) {
            $this->warn('Lead kill switch is ON; nothing queued.');

            return self::SUCCESS;
        }

        $dueBefore = now()->subMinutes((int) config('leads.pull_interval_minutes', 50));
        $only = $this->option('profile');

        $profiles = LeadProfile::query()
            ->active()
            ->whereNotNull('lead_query')
            ->when($only, fn ($query) => $query->whereKey($only))
            ->when(! $only, fn ($query) => $query->where(
                fn ($due) => $due->whereNull('last_pulled_at')->orWhere('last_pulled_at', '<=', $dueBefore)
            ))
            ->get();

        foreach ($profiles as $profile) {
            PullLeadProfileJob::dispatch($profile->id);
        }

        $this->info("Queued {$profiles->count()} lead pull(s).");

        return self::SUCCESS;
    }
}
