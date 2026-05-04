<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One WhatsApp Cloud API phone number with its Meta credentials.
 *
 * Single-driver model after Phase 8 cleanup — every instance is Cloud API.
 * The legacy `driver`, `instance_name` (now repurposed as internal handle),
 * and `api_token` columns remain on the table for forward-compat but are no
 * longer populated by application code.
 */
class WhatsAppInstance extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_CONNECTED = 'CONNECTED';
    public const STATUS_DISCONNECTED = 'DISCONNECTED';
    public const STATUS_PENDING = 'PENDING';

    protected $table = 'whatsapp_instances';

    protected $fillable = [
        'user_id',
        'instance_name',
        'phone_number',
        'display_name',
        'status',
        'waba_id',
        'phone_number_id',
        'access_token',
        'app_secret',
        'webhook_verify_token',
        'business_phone_number',
        'quality_rating',
        'messaging_limit_tier',
        'is_default',
    ];

    /**
     * Hide the credentials from any accidental ->toArray() / JSON serialization.
     */
    protected $hidden = [
        'access_token',
        'app_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            // Encrypt-at-rest so a DB dump leak doesn't expose the raw tokens.
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaigns(): HasMany
    {
        // Explicit FK because the column is `instance_id`, not Laravel's
        // auto-derived `whats_app_instance_id`.
        return $this->hasMany(Campaign::class, 'instance_id');
    }

    /**
     * True when the instance has every credential needed to talk to Meta.
     * False during the brief window between create and credential validation.
     */
    public function isReady(): bool
    {
        return filled($this->waba_id)
            && filled($this->phone_number_id)
            && filled($this->access_token);
    }
}
