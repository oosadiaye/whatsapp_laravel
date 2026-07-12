<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent note logged against a call. Append-only timeline entry — created,
 * never edited — so the Call Workspace shows an auditable record of what the
 * agent captured during/after the conversation.
 */
class CallNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_log_id',
        'user_id',
        'body',
    ];

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
