<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\HandleLeadReplyJob;
use App\Jobs\RunLeadOutreachJob;
use App\Models\LeadReply;
use App\Services\Leads\Outreach\ReplyHandler;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadProfile;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Support\LeadConfig;
use Illuminate\Console\Command;

class RunLeadOutreach extends Command
{
    protected $signature = 'leads:outreach {--profile= : Only this lead profile id}';

    protected $description = 'Draft first emails and follow-ups for active lead profiles and send everything that is approved.';

    public function handle(LeadMessageSender $sender): int
    {
        if (LeadConfig::killSwitchOn()) {
            $this->warn('Lead kill switch is ON; nothing queued.');

            return self::SUCCESS;
        }

        $profiles = LeadProfile::query()
            ->active()
            ->when($this->option('profile'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('leads', fn ($leads) => $leads->where(function ($work) {
                $work->where(fn ($new) => $new->where('status', Lead::STATUS_QUALIFIED)->whereNotNull('email'))
                    ->orWhere(fn ($due) => $due->where('status', Lead::STATUS_CONTACTED)->whereNotNull('next_followup_at')->where('next_followup_at', '<=', now()));
            }))
            ->get();

        // A reply the classifier never finished (worker died, timeout) is picked up again; the attempt count bounds it.
        $stuck = LeadReply::query()
            ->where('status', LeadReply::STATUS_NEW)
            ->where('created_at', '<', now()->subMinutes(10))
            ->where('classify_attempts', '<', ReplyHandler::MAX_ATTEMPTS)
            ->limit(20)
            ->pluck('id');
        foreach ($stuck as $replyId) {
            HandleLeadReplyJob::dispatch($replyId);
        }
        LeadReply::query()
            ->where('status', LeadReply::STATUS_NEW)
            ->where('created_at', '<', now()->subMinutes(10))
            ->where('classify_attempts', '>=', ReplyHandler::MAX_ATTEMPTS)
            ->update(['status' => LeadReply::STATUS_NEEDS_HUMAN, 'summary' => 'The AI could not read this reply.']);

        foreach ($profiles as $profile) {
            RunLeadOutreachJob::dispatch($profile->id);
        }

        // Messages a person just approved (or that were held by a cap) go out even when no profile has new drafting to do.
        $sent = LeadMessage::query()->where('status', LeadMessage::STATUS_APPROVED)->exists() ? $sender->sweep() : 0;

        $this->info("Queued {$profiles->count()} outreach run(s); sent {$sent} approved message(s).");

        return self::SUCCESS;
    }
}
