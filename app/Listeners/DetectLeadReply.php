<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Mailbox\MailReceived;
use App\Services\Leads\Outreach\ReplyDetector;
use Illuminate\Support\Facades\Log;

/**
 * Every inbound email from a connected mailbox is checked against the leads we have written to.
 * Never throws: a problem here must not stop the mailbox sync that triggered it.
 */
class DetectLeadReply
{
    public function __construct(private readonly ReplyDetector $detector) {}

    public function handle(MailReceived $event): void
    {
        try {
            $this->detector->detect($event->message);
        } catch (\Throwable $e) {
            Log::error('Lead reply detection failed', ['message_id' => $event->message->id, 'error' => $e->getMessage()]);
        }
    }
}
