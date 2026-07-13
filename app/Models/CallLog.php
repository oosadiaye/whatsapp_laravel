<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CallLog extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    /** AI/transcription pipeline states driving the Call Workspace panel. */
    public const AI_STATUS_NONE = 'none';           // nothing captured yet
    public const AI_STATUS_PENDING = 'pending';     // recording uploaded, queued
    public const AI_STATUS_PROCESSING = 'processing'; // Gemini call in flight
    public const AI_STATUS_COMPLETED = 'completed';
    public const AI_STATUS_FAILED = 'failed';
    public const AI_STATUS_UNAVAILABLE = 'unavailable'; // recording disabled/unsupported

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_RINGING = 'ringing';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ENDED = 'ended';
    public const STATUS_MISSED = 'missed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_FAILED = 'failed';

    public const STATUSES_IN_FLIGHT = [
        self::STATUS_INITIATED,
        self::STATUS_RINGING,
        self::STATUS_CONNECTED,
    ];

    public const STATUSES_TERMINAL = [
        self::STATUS_ENDED,
        self::STATUS_MISSED,
        self::STATUS_DECLINED,
        self::STATUS_FAILED,
    ];

    public const PROVIDER_META_WHATSAPP = 'meta_whatsapp';
    public const PROVIDER_AFRICAS_TALKING = 'africas_talking';

    public const PROVIDERS = [
        self::PROVIDER_META_WHATSAPP,
        self::PROVIDER_AFRICAS_TALKING,
    ];

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'whatsapp_instance_id',
        'direction',
        'provider',
        'meta_call_id',
        'provider_session_id',
        'status',
        'from_phone',
        'to_phone',
        'started_at',
        'connected_at',
        'ended_at',
        'duration_seconds',
        'cost_estimate_kobo',
        'quality_metrics',
        'failure_reason',
        'placed_by_user_id',
        'raw_event_log',
        'sdp_offer',
        'sdp_answer',
        'answered_by_session_id',
        'transfer_target',
        'transferred_to_user_id',
        'transfer_type',
        'transferred_at',
        'recording_path',
        'recording_mime',
        'recording_uploaded_at',
        'transcript',
        'ai_summary',
        'ai_key_points',
        'ai_status',
        'ai_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'transferred_at' => 'datetime',
            'recording_uploaded_at' => 'datetime',
            'duration_seconds' => 'integer',
            'raw_event_log' => 'array',
            'ai_key_points' => 'array',
            'quality_metrics' => \App\Casts\PreservedJsonArray::class,
        ];
    }

    /**
     * Hide the raw recording key + WebRTC SDP blobs from accidental JSON
     * serialization — they're internal plumbing, not client-facing.
     */
    protected $hidden = [
        'recording_path',
        'sdp_offer',
        'sdp_answer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class, 'whatsapp_instance_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CallNote::class)->oldest();
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function hasRecording(): bool
    {
        return filled($this->recording_path);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::STATUSES_IN_FLIGHT, true);
    }

    /**
     * Append one webhook event payload to raw_event_log without overwriting
     * earlier events. Stores [{event, timestamp, payload}, ...].
     *
     * @param  array<string, mixed>  $payload  the raw webhook event body
     */
    public function appendRawEvent(string $event, array $payload): void
    {
        $log = $this->raw_event_log ?? [];
        $log[] = [
            'event' => $event,
            'timestamp' => Carbon::now()->toIso8601String(),
            'payload' => $payload,
        ];
        $this->raw_event_log = $log;
    }
}
