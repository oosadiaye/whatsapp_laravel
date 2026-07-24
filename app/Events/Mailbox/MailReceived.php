<?php

declare(strict_types=1);

namespace App\Events\Mailbox;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when EmailSyncService stores a NEW inbound message (plan B6).
 * Routes to the account OWNER's user-scoped private channel — the same
 * `user.{id}` pattern the call stack uses — so only that employee's open inbox
 * tabs refresh. A teammate with mailbox.view_all still sees it on their next
 * render (they listen on their OWN channel, not their colleagues'); realtime is
 * best-effort push, correctness comes from the query.
 */
class MailReceived implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public EmailAccount $account,
        public EmailMessage $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.(int) $this->account->user_id);
    }

    public function broadcastAs(): string
    {
        return 'mail.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->account->id,
            'thread_id' => $this->message->email_thread_id,
            'subject' => $this->message->subject,
            'from' => $this->message->from_email,
        ];
    }
}
