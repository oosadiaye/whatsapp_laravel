<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * One entry in a card's history.
 *
 * Append-only by construction: no `updated_at`, and no update path anywhere in
 * the app. Rows are written by listeners on the board events, which means any
 * future write path — a console command, an API endpoint, a second component —
 * is captured for free rather than needing to remember to call something.
 */
class TaskActivity extends Model
{
    /** @use HasFactory<TaskActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Declared rather than inferred.
     *
     * Every other table in this schema is plural and matches Eloquent's
     * default; this one is not, because "task_activities" is not a word. Naming
     * it here rather than leaving it to a default is the point: the day someone
     * writes a migration against `task_activities`, the query fails loudly
     * instead of silently reading an empty table.
     */
    protected $table = 'task_activity';

    public const TYPE_CREATED = 'created';

    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_ASSIGNMENT_CHANGED = 'assignment_changed';

    public const TYPE_COMMENTED = 'commented';

    public const TYPE_COMMENT_REMOVED = 'comment_removed';

    public const TYPE_TRIAGE_UPDATED = 'triage_updated';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_DELETED = 'deleted';

    public const TYPES = [
        self::TYPE_CREATED,
        self::TYPE_STATUS_CHANGED,
        self::TYPE_ASSIGNMENT_CHANGED,
        self::TYPE_COMMENTED,
        self::TYPE_COMMENT_REMOVED,
        self::TYPE_TRIAGE_UPDATED,
        self::TYPE_UPDATED,
        self::TYPE_DELETED,
    ];

    protected $fillable = [
        'task_id',
        'board_id',
        'user_id',
        'type',
        'event_key',
        'from_status',
        'to_status',
        'metadata',
    ];

    /**
     * Write one row for one event, tolerating a re-dispatch.
     *
     * `event_key` is unique, and that index is load-bearing: it is what makes
     * "this notification came from that log row" unambiguous, and without it a
     * duplicated event would quietly produce two history entries that nothing
     * could tell apart.
     *
     * The consequence is that a second write for the same key is an integrity
     * violation. Letting that escape would 500 the whole request - a queue
     * retry, a double dispatch, or a wrapped transaction all become a broken
     * save-button click over something that is, underneath, already recorded.
     * So the duplicate is caught and the original row returned.
     *
     * Swallowing this is safe because the key is a per-event UUID: two genuinely
     * different events cannot collide, so a conflict always means the same
     * event was seen twice, never that the log lost an entry.
     *
     * @return static|null null only if the event carried no key to correlate on
     */
    public static function recordOnce(?string $eventKey, array $attributes): ?static
    {
        if ($eventKey === null) {
            return static::create($attributes);
        }

        try {
            return static::create([...$attributes, 'event_key' => $eventKey]);
        } catch (UniqueConstraintViolationException) {
            return static::where('event_key', $eventKey)->first();
        }
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        // withTrashed() so the last entry of a deleted card still resolves its
        // title. Returns null only once the row is hard-deleted, which the log
        // is built to outlive.
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who this entry has already been mailed to.
     *
     * Not read anywhere in the app - it exists so the notification guard can be
     * reasoned about and tested against the log rather than against a private
     * table. Note the direction: the ledger points at the log, but the log never
     * reads a "notified_at" column, because one entry fans out to many people
     * and a single timestamp on the row would claim something untrue.
     */
    public function notifications(): HasMany
    {
        // Explicit key: Laravel would guess "task_activity_id" from this model
        // name, and the column is "activity_id".
        return $this->hasMany(TaskActivityNotification::class, 'activity_id');
    }

    /**
     * Verb phrase for the history panel - the action only, no actor.
     *
     * The panel renders the actor separately (bolded, before this phrase), so
     * the actor's name deliberately does not come through here. An earlier
     * version took it as an argument and built the full sentence, which meant
     * two sources for the same name and a way for the two to disagree.
     */
    public function summary(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'created this card',
            self::TYPE_DELETED => 'deleted this card',
            self::TYPE_STATUS_CHANGED => sprintf(
                'moved this card from %s to %s',
                TaskStatus::label((string) $this->from_status),
                TaskStatus::label((string) $this->to_status),
            ),
            self::TYPE_ASSIGNMENT_CHANGED => 'changed who this card is assigned to',
            self::TYPE_COMMENTED => 'left a comment',
            self::TYPE_COMMENT_REMOVED => 'removed a comment',
            self::TYPE_TRIAGE_UPDATED => 'changed the deadline, priority or estimate',
            self::TYPE_UPDATED => 'edited the description',
            // An unknown type still reads as *something happened*. Better a
            // vague line than a blank row in a list of real events.
            default => 'changed this card',
        };
    }

    /**
     * Only a transition carries a from/to pair; everything else would store two
     * nulls that no report ever reads.
     */
    public function isTransition(): bool
    {
        return $this->type === self::TYPE_STATUS_CHANGED;
    }
}
