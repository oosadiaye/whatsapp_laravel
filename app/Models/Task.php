<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    public const PRIORITY_LOW = 1;

    public const PRIORITY_NORMAL = 2;

    public const PRIORITY_HIGH = 3;

    public const PRIORITY_URGENT = 4;

    /**
     * Rank => label, ordered for display. Validated against the keys, so
     * adding a level is a one-line change here plus a sort that already works.
     */
    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_URGENT => 'Urgent',
    ];

    /**
     * The three board sort modes.
     *
     * 'manual' is the only one that reads `position`; the other two are views
     * over the same stored order, which is why dragging in a sorted column
     * changes the column but never rewrites position.
     */
    public const SORT_MANUAL = 'manual';

    public const SORT_DUE = 'due';

    public const SORT_PRIORITY = 'priority';

    public const SORT_MODES = [
        self::SORT_MANUAL,
        self::SORT_DUE,
        self::SORT_PRIORITY,
    ];

    protected $fillable = [
        'user_id',
        'board_id',
        'title',
        'description',
        'status',
        'position',
        'priority',
        'due_at',
        'estimate_minutes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            // Cast so a card read back from SQLite hands back an int rather than
            // "3", which would make PRIORITY_LABELS lookups miss.
            'priority' => 'integer',
            'estimate_minutes' => 'integer',
            'due_at' => 'datetime',
        ];
    }

    /**
     * Cards that are past their deadline and not finished.
     *
     * The completed-status list is passed in rather than resolved here because
     * TaskStatus::columns() is a query, and calling it per card would make a
     * 50-card board 50 queries slower. render() already has the list.
     */
    public function scopeOverdue(Builder $query, array $completedSlugs): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            // A card sitting in "done" is never at risk, however old its
            // deadline. Marking finished work red is how a board stops being
            // believed.
            ->whereNotIn('status', $completedSlugs);
    }

    /**
     * Display label for the card's priority, tolerating a value written by an
     * older build or a hand-edited database.
     */
    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? 'Normal';
    }

    /**
     * True when the card is past its deadline and its column is not a
     * completed one. $completedSlugs is supplied by the caller for the same
     * N+1 reason as scopeOverdue().
     */
    public function isOverdue(array $completedSlugs): bool
    {
        if ($this->due_at === null) {
            return false;
        }

        if (in_array($this->status, $completedSlugs, true)) {
            return false;
        }

        return $this->due_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * The card's history.
     *
     * Read-only by convention, and worth being explicit about why there is no
     * foreign key backing it: task_activity is deliberately unconstrained so a
     * log row can outlive the card it describes. That makes this relation a
     * lookup rather than a guarantee, so nothing may rely on it for
     * authorisation or cascade behaviour.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class, 'task_id');
    }

    /**
     * Who is doing the work. Distinct from user_id(), which stays the creator -
     * the two are independent, and collapsing them would make "who raised this"
     * unanswerable the moment a card is assigned on.
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees');
    }

    /**
     * Who asked to be kept informed. Opt-in, so it carries no authority: a
     * watcher can see nothing the permission system would not already allow.
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers');
    }
}
