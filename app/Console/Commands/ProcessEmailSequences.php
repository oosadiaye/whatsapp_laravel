<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSequenceStep;
use App\Services\MailClient\OutboundEmail;
use App\Jobs\SendUserEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessEmailSequences extends Command
{
    protected $signature = 'email-sequences:process';

    protected $description = 'Advance email sequence recipients whose delay has elapsed, sending the next step.';

    public function handle(): int
    {
        $sequences = EmailSequence::query()
            ->where('status', EmailSequence::STATUS_ACTIVE)
            ->with('steps')
            ->get();

        $processed = 0;

        foreach ($sequences as $sequence) {
            if ($sequence->steps->isEmpty()) {
                continue;
            }

            $due = EmailSequenceRecipient::query()
                ->where('email_sequence_id', $sequence->id)
                ->where('status', EmailSequence::RECIPIENT_PENDING)
                ->where(function ($q) {
                    $q->whereNull('next_send_at')
                      ->orWhere('next_send_at', '<=', now());
                })
                ->limit(50)
                ->get();

            foreach ($due as $recipient) {
                $this->sendStep($sequence, $recipient);
                $processed++;
            }
        }

        if ($processed > 0) {
            Log::info("Email sequences: processed {$processed} recipient(s).");
        }

        $this->info("Processed {$processed} recipient(s).");

        return self::SUCCESS;
    }

    private function sendStep(EmailSequence $sequence, EmailSequenceRecipient $recipient): void
    {
        $step = $sequence->steps->firstWhere('order', $recipient->current_step);

        if ($step === null) {
            $recipient->update(['status' => EmailSequence::RECIPIENT_COMPLETED, 'completed_at' => now()]);
            return;
        }

        $account = $sequence->account;

        if ($account === null) {
            Log::warning("Sequence {$sequence->id}: no account configured, skipping recipient {$recipient->id}");
            return;
        }

        // Defence in depth against H2 (cross-account send-as): never dispatch
        // through a mailbox that doesn't belong to the sequence's owner, even if
        // a stale row predates the scoped-validation fix in EmailSequenceController.
        if ($account->user_id !== $sequence->user_id) {
            Log::warning("Sequence {$sequence->id}: account {$account->id} is not owned by the sequence owner, skipping recipient {$recipient->id}.");
            return;
        }

        $outbound = new OutboundEmail(
            to: [$recipient->email],
            subject: $step->subject,
            bodyHtml: $step->body_html,
            bodyText: $step->body_text,
            cc: [],
            inReplyTo: null,
            references: null,
            threadId: null,
            attachments: [],
        );

        SendUserEmail::dispatch($account->id, $outbound);

        $nextStep = $sequence->steps->firstWhere('order', $step->order + 1);

        if ($nextStep === null) {
            $recipient->update([
                'status' => EmailSequence::RECIPIENT_SENT,
                'current_step' => $step->order,
                'last_sent_at' => now(),
                'completed_at' => now(),
            ]);
        } else {
            $delayHours = $nextStep->totalDelayHours();
            $recipient->update([
                'current_step' => $nextStep->order,
                'last_sent_at' => now(),
                'next_send_at' => $delayHours > 0 ? now()->addHours($delayHours) : now(),
            ]);
        }
    }
}
