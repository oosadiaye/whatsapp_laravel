<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

use App\Models\LeadMessage;
use App\Models\LeadReply;

/** What is waiting for a person: drafts to approve and replies nobody has looked at yet. Feeds the sidebar badge. */
final class LeadAttention
{
    /**
     * @return array{approvals: int, replies: int, total: int}
     */
    public static function counts(): array
    {
        $approvals = LeadMessage::query()->where('status', LeadMessage::STATUS_PENDING)->count();
        $replies = LeadReply::query()->whereNull('seen_at')->count();

        return ['approvals' => $approvals, 'replies' => $replies, 'total' => $approvals + $replies];
    }
}
