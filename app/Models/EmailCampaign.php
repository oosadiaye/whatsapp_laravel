<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An email broadcast campaign. Targets contact groups, sends via Laravel Mail,
 * and supports one-off scheduling + simple recurrence.
 */
class EmailCampaign extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';

    public const RECURRENCE_NONE = 'none';
    public const RECURRENCE_DAILY = 'daily';
    public const RECURRENCE_WEEKLY = 'weekly';
    public const RECURRENCE_MONTHLY = 'monthly';

    public const RECURRENCES = [
        self::RECURRENCE_NONE,
        self::RECURRENCE_DAILY,
        self::RECURRENCE_WEEKLY,
        self::RECURRENCE_MONTHLY,
    ];

    /** Statuses a campaign can still be edited in. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_PAUSED];

    protected $fillable = [
        'user_id',
        'name',
        'subject',
        'from_name',
        'reply_to',
        'body_html',
        'status',
        'scheduled_at',
        'recurrence',
        'recurrence_until',
        'last_run_at',
        'started_at',
        'completed_at',
        'rate_per_minute',
        'total_recipients',
        'sent_count',
        'failed_count',
        'opened_count',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'recurrence_until' => 'datetime',
            'last_run_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rate_per_minute' => 'integer',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'opened_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contactGroups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'email_campaign_group', 'email_campaign_id', 'group_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== self::RECURRENCE_NONE;
    }
}
