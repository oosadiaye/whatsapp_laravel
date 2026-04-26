<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppInstance extends Model
{
    use HasFactory, SoftDeletes;

    public const DRIVER_CLOUD = 'cloud';
    public const DRIVER_EVOLUTION = 'evolution';

    protected $table = 'whatsapp_instances';

    protected $fillable = [
        'user_id',
        'driver',
        'instance_name',
        'phone_number',
        'display_name',
        'status',
        'api_token',
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
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            // Encrypt-at-rest so a DB dump leak doesn't expose the raw tokens.
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'api_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function isCloud(): bool
    {
        return $this->driver === self::DRIVER_CLOUD;
    }

    public function isEvolution(): bool
    {
        return $this->driver === self::DRIVER_EVOLUTION;
    }

    /**
     * True only when the Cloud API instance has every credential needed to send.
     */
    public function isCloudReady(): bool
    {
        return $this->isCloud()
            && filled($this->waba_id)
            && filled($this->phone_number_id)
            && filled($this->access_token);
    }
}
