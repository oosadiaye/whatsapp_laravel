<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invited person: a staff user, a contact, or just an email address. */
class MeetingAttendee extends Model
{
    use HasFactory;

    public const RESPONSE_PENDING = 'pending';

    public const RESPONSE_ACCEPTED = 'accepted';

    public const RESPONSE_DECLINED = 'declined';

    public const RESPONSES = [self::RESPONSE_PENDING, self::RESPONSE_ACCEPTED, self::RESPONSE_DECLINED];

    protected $fillable = [
        'meeting_id', 'user_id', 'contact_id', 'email', 'name',
        'response', 'response_token', 'responded_at', 'invited_at', 'invite_error',
    ];

    /** The private link token is never serialised (Livewire payloads, JSON, logs). */
    protected $hidden = ['response_token'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'invited_at' => 'datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }
}
