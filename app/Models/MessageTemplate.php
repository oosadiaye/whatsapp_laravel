<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTemplate extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_LOCAL = 'LOCAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'user_id',
        'whatsapp_instance_id',
        'name',
        'whatsapp_template_id',
        'language',
        'status',
        'content',
        'components',
        'synced_at',
        'media_path',
        'media_type',
        'category',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class);
    }

    /**
     * True when this template was synced from Meta (Cloud API) via Evolution API.
     */
    public function isRemote(): bool
    {
        return $this->whatsapp_template_id !== null;
    }
}
