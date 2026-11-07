<?php

declare(strict_types=1);

namespace App\Events\Leads;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed to one person's private channel the moment a prospect answers (or an AI decision needs them).
 * Sent immediately, not through the queue, so the alert does not wait behind other jobs. It carries no
 * message text: the toast says who and what kind; the content stays behind the login.
 */
class LeadReplyAlert implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly int $leadId,
        public readonly string $leadName,
        public readonly string $company,
        public readonly string $kind, // reply | needs_attention | approval
        public readonly string $headline,
        public readonly string $url,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'lead.alert';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'lead' => $this->leadName,
            'company' => $this->company,
            'kind' => $this->kind,
            'headline' => $this->headline,
            'url' => $this->url,
        ];
    }
}
