<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in browser session, as seen by the user monitor.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?\Illuminate\Support\Carbon $last_active_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 */
class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'last_active_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not revoked and still within the framework's session lifetime. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('last_active_at', '>=', now()->subMinutes((int) config('session.lifetime', 120)));
    }
}
