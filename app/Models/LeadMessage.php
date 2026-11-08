<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\OwnedThroughLead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email to a lead that the AI drafted: waiting for approval, sent, or discarded. */
class LeadMessage extends Model
{
    use OwnedThroughLead;

    public const KIND_FIRST = 'first';

    public const KIND_FOLLOWUP = 'followup';

    public const KIND_REPLY = 'reply';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'lead_id', 'kind', 'status', 'step', 'subject', 'body', 'by_ai', 'lead_reply_id',
        'message_id', 'decided_by', 'failure', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['by_ai' => 'boolean', 'step' => 'integer', 'sent_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(LeadReply::class, 'lead_reply_id');
    }
}
