<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's link to their Google Calendar. Tokens are encrypted at rest and hidden from serialisation.
 */
class GoogleConnection extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    protected $fillable = [
        'user_id', 'google_email', 'access_token', 'refresh_token', 'expires_at', 'scopes',
        'status', 'last_synced_at', 'last_error', 'last_import_count',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_import_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The user's connection if it can be used right now (connected and not waiting for a reconnect). */
    public static function usableFor(?int $userId): ?self
    {
        if ($userId === null) {
            return null;
        }

        $connection = static::query()->where('user_id', $userId)->first();

        return $connection?->isUsable() ? $connection : null;
    }
}
