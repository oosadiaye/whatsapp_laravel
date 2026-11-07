<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prospect pulled from a lead platform. Deliberately NOT a Contact: unvetted
 * scraped data must never be reachable by a WhatsApp or email campaign. Only a
 * qualified lead is promoted to a Contact (later phase).
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_MEETING = 'meeting';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_QUALIFIED, self::STATUS_REJECTED, self::STATUS_CONTACTED,
        self::STATUS_REPLIED, self::STATUS_MEETING, self::STATUS_WON, self::STATUS_LOST, self::STATUS_UNSUBSCRIBED,
    ];

    /** Platform says an email exists but it has not been revealed (revealing costs credits). */
    public const EMAIL_UNREVEALED = 'unrevealed';

    public const EMAIL_UNAVAILABLE = 'unavailable';

    public const TIER_HOT = 'hot';

    public const TIER_WARM = 'warm';

    public const TIER_REJECTED = 'rejected';

    protected $fillable = [
        'lead_profile_id', 'source', 'source_id', 'email', 'email_status', 'name', 'title', 'company',
        'domain', 'country', 'phone', 'linkedin_url', 'raw', 'score', 'tier', 'qualification_reason',
        'status', 'contact_id', 'owner_user_id', 'last_activity_at',
        'qualify_attempts', 'qualified_at', 'revealed_at',
        'outreach_started_at', 'next_followup_at', 'followups_sent',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'score' => 'integer',
            'last_activity_at' => 'datetime',
            'qualify_attempts' => 'integer',
            'qualified_at' => 'datetime',
            'revealed_at' => 'datetime',
            'outreach_started_at' => 'datetime',
            'next_followup_at' => 'datetime',
            'followups_sent' => 'integer',
        ];
    }

    /**
     * Emails are stored trimmed and lowercased so the unique index behaves the same
     * on MySQL (case-insensitive) and SQLite (case-sensitive), and a blank becomes NULL.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value)),
        );
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(LeadProfile::class, 'lead_profile_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LeadMessage::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(LeadReply::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }
}
