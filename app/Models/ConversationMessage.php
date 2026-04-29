<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'conversation_id',
        'direction',
        'whatsapp_message_id',
        'type',
        'body',
        'media_path',
        'media_mime',
        'media_size_bytes',
        'sent_by_user_id',
        'status',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'media_size_bytes' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function hasMedia(): bool
    {
        return $this->media_path !== null;
    }
}
