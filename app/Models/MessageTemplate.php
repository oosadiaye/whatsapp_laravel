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

    /**
     * Returns the HEADER component's media format if it requires a media URL
     * at send time ('IMAGE', 'VIDEO', 'DOCUMENT'), or null if the template's
     * header is text or absent.
     *
     * Drives both the campaign form's conditional "Header Media URL" field
     * and the StoreCampaignRequest pre-flight guard that prevents 132012.
     */
    public function headerMediaFormat(): ?string
    {
        $components = $this->components ?? [];
        if (! is_array($components)) {
            return null;
        }

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type !== 'HEADER') {
                continue;
            }
            $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
            if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                return $format;
            }
        }

        return null;
    }
}
