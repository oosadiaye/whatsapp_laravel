<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EmailLeadScoringService;
use Illuminate\Console\Command;

class ScoreEmailLeads extends Command
{
    protected $signature = 'email:score-leads';

    protected $description = 'Recalculate lead scores for all contacts based on email engagement.';

    public function handle(EmailLeadScoringService $service): int
    {
        $count = $service->scoreAll();

        $this->info("Scored {$count} contact(s).");

        return self::SUCCESS;
    }
}
