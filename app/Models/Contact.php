<?php

namespace App\Models;

use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'phone',
        'name',
        'custom_fields',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contactGroups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_group', 'contact_id', 'group_id');
    }

    // Alias for views that reference $contact->groups
    public function groups(): BelongsToMany
    {
        return $this->contactGroups();
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }

    /**
     * All inbound + outbound chat messages this contact has exchanged with us,
     * across every conversation/instance. Used for engagement detection
     * (Phase 13.1 opt-in proxy) and future activity-timeline rendering.
     */
    public function conversationMessages(): HasManyThrough
    {
        return $this->hasManyThrough(
            ConversationMessage::class,
            Conversation::class,
            'contact_id',         // FK on conversations table
            'conversation_id',    // FK on conversation_messages table
            'id',                 // local key on contacts
            'id',                 // local key on conversations
        );
    }

    /**
     * All call_logs this contact has been part of, across every conversation.
     * Used for engagement detection (Phase 13.1 opt-in proxy).
     */
    public function callLogs(): HasManyThrough
    {
        return $this->hasManyThrough(
            CallLog::class,
            Conversation::class,
            'contact_id',
            'conversation_id',
            'id',
            'id',
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
