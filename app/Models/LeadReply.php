<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message a lead sent back, with what the AI made of it. */
class LeadReply extends Model
{
    public const INTERESTED = 'interested';

    public const QUESTION = 'question';

    public const NOT_NOW = 'not_now';

    public const NOT_INTERESTED = 'not_interested';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const OUT_OF_OFFICE = 'out_of_office';

    public const OTHER = 'other';

    public const CLASSES = [
        self::INTERESTED, self::QUESTION, self::NOT_NOW, self::NOT_INTERESTED,
        self::UNSUBSCRIBE, self::OUT_OF_OFFICE, self::OTHER,
    ];

    public const STATUS_NEW = 'new';

    public const STATUS_HANDLED = 'handled';

    public const STATUS_NEEDS_HUMAN = 'needs_human';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $fillable = [
        'lead_id', 'email_message_id', 'classification', 'confidence', 'summary', 'status', 'seen_at', 'classify_attempts',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'integer', 'seen_at' => 'datetime', 'classify_attempts' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
