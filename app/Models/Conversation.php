<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\CallLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One chat thread between a contact and one of the user's WhatsApp numbers.
 *
 * Lifecycle:
 *   - Created on first inbound message from contact (or first outbound to contact)
 *   - last_message_at / unread_count denormalized for fast inbox sorting
 *   - assigned_to_user_id null = unassigned (everyone can see); set = restricted
 *     to that agent + admins/managers
 *   - Never deleted — soft-delete or just keep around indefinitely
 */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_id',
        'whatsapp_instance_id',
        'assigned_to_user_id',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class, 'whatsapp_instance_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class)->orderBy('created_at');
    }

    /**
     * The 24-hour conversation window. Replies outside this window must use
     * an approved template — Meta will reject freeform sends with error 131_026.
     *
     * Window opens on every INBOUND message from the contact, not on outbound
     * sends. So if a contact messages us at 10:00, we have until 10:00 the
     * next day to send anything freeform; sending more outbound during that
     * window does NOT extend it.
     */
    public function isWindowOpen(): bool
    {
        if ($this->last_inbound_at === null) {
            return false;
        }

        return $this->last_inbound_at->diffInHours(Carbon::now()) < 24;
    }

    /**
     * Hours remaining in the 24-hour reply window. Returns 0 if expired.
     */
    public function windowHoursLeft(): int
    {
        if ($this->last_inbound_at === null) {
            return 0;
        }

        $hoursLeft = 24 - (int) $this->last_inbound_at->diffInHours(Carbon::now());

        return max($hoursLeft, 0);
    }
}
