<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voicemail left by an inbound caller (office closed, agents busy, or the
 * caller chose the voicemail IVR option). AT records the audio and posts a
 * recordingUrl to the webhook, which creates this row.
 */
class Voicemail extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_log_id',
        'conversation_id',
        'contact_id',
        'from_phone',
        'recording_url',
        'duration_seconds',
        'is_heard',
        'heard_by_user_id',
        'heard_at',
    ];

    protected function casts(): array
    {
        return [
            'is_heard' => 'boolean',
            'duration_seconds' => 'integer',
            'heard_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function heardBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'heard_by_user_id');
    }
}
