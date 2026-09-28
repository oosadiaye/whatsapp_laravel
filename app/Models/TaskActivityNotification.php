<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskActivityNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One intended-and-completed delivery of one activity to one person.
 *
 * This is a ledger, not a log: a row means "this person has been handed this
 * notification", and the unique (activity_id, user_id) index is what makes that
 * claim enforceable. See TaskNotificationLedger for the protocol.
 */
class TaskActivityNotification extends Model
{
    /** @use HasFactory<TaskActivityNotificationFactory> */
    use HasFactory;

    /**
     * No foreign keys, matching task_activity. These rows are derived from a
     * log entry, so they have no independent meaning and nothing should be able
     * to be constrained by them. When old activity is pruned, prune these too.
     */
    protected $table = 'task_activity_notifications';

    public const UPDATED_AT = null;

    protected $fillable = [
        'activity_id',
        'user_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        // Explicit key for the same reason as the inverse: the column is
        // "activity_id", not the guessed "task_activity_id".
        return $this->belongsTo(TaskActivity::class, 'activity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
