<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendUserEmail;
use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Services\MailClient\OutboundEmail;
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
                ->where(function ($q) {
                    // Due when PENDING with an elapsed (or absent) next_send_at …
                    $q->where(fn ($pending) => $pending
                        ->where('status', EmailSequence::RECIPIENT_PENDING)
                        ->where(fn ($timing) => $timing
                            ->whereNull('next_send_at')
                            ->orWhere('next_send_at', '<=', now())
                        )
                    );
                    // … or when a prior claim went stale (a process died after
                    // claiming but before advancing — reclaim after the TTL).
                    $q->orWhere(fn ($stale) => $stale
                        ->where('status', EmailSequence::RECIPIENT_SENDING)
                        ->where('next_send_at', '<=', now())
                    );
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

        // Atomic claim (mirrors the campaign QUEUED→SENDING guard): only ONE cron
        // pass may dispatch this recipient's step. A concurrent pass (or a
        // released/retried command) finds the row no longer PENDING and skips it,
        // preventing the duplicate send that a plain check-then-act produced. A
        // STALE claim (SENDING with an elapsed TTL sentinel) is also reclaimable.
        $claimed = EmailSequenceRecipient::query()
            ->whereKey($recipient->id)
            ->where(function ($q) {
                $q->where(fn ($pending) => $pending
                    ->where('status', EmailSequence::RECIPIENT_PENDING)
                    ->where(fn ($timing) => $timing
                        ->whereNull('next_send_at')
                        ->orWhere('next_send_at', '<=', now())
                    )
                );
                $q->orWhere(fn ($stale) => $stale
                    ->where('status', EmailSequence::RECIPIENT_SENDING)
                    ->where('next_send_at', '<=', now())
                );
            })
            ->update([
                'status' => EmailSequence::RECIPIENT_SENDING,
                // Claim TTL: if this process dies between the dispatch below and
                // the advance, the stale claim self-heals after 10 minutes (the
                // due query reclaims RECIPIENT_SENDING rows whose sentinel passed).
                'next_send_at' => now()->addMinutes(10),
            ]);

        if ($claimed === 0) {
            return; // another pass already claimed/advanced this recipient
        }

        $recipient->refresh();

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
                'next_send_at' => null,
                'completed_at' => now(),
            ]);
        } else {
            $delayHours = $nextStep->totalDelayHours();
            $recipient->update([
                'status' => EmailSequence::RECIPIENT_PENDING,
                'current_step' => $nextStep->order,
                'last_sent_at' => now(),
                'next_send_at' => $delayHours > 0 ? now()->addHours($delayHours) : now(),
            ]);
        }
    }
}
