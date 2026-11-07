<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LeadReply;
use App\Services\Leads\Outreach\LeadMessageSender;
use App\Services\Leads\Outreach\ReplyHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Classifies one prospect reply and acts on it. A temporary AI failure is retried a few times, then escalated. */
class HandleLeadReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $replyId) {}

    public function handle(ReplyHandler $handler, LeadMessageSender $sender): void
    {
        $reply = LeadReply::find($this->replyId);
        if ($reply === null) {
            return;
        }

        if ($handler->handle($reply) === 'retry') {
            // Back off: 1, then 3 minutes. The attempt count lives on the row, so a crash cannot loop forever.
            self::dispatch($this->replyId)->delay(now()->addMinutes($reply->fresh()->classify_attempts === 1 ? 1 : 3));

            return;
        }

        // A fully automatic answer is sent straight away instead of waiting for the five-minute sweep.
        $sender->sweep();
    }
}
