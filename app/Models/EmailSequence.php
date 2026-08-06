<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSequence extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RECIPIENT_PENDING = 'pending';

    public const RECIPIENT_SENDING = 'sending';

    public const RECIPIENT_SENT = 'sent';

    public const RECIPIENT_OPENED = 'opened';

    public const RECIPIENT_CLICKED = 'clicked';

    public const RECIPIENT_REPLIED = 'replied';

    public const RECIPIENT_BOUNCED = 'bounced';

    public const RECIPIENT_UNSUBSCRIBED = 'unsubscribed';

    public const RECIPIENT_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'email_account_id',
        'name',
        'status',
        'target_filters',
        'total_recipients',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_filters' => 'array',
            'total_recipients' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EmailSequenceStep::class)->orderBy('order');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailSequenceRecipient::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
