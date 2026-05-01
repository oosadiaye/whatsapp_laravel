<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'instance_id',
        'message_template_id',
        'template_language',
        'header_media_url',  // populated by CampaignController::storeHeaderMedia() from the form's "Header media" file upload; passed to Meta as the template-header parameter
        'name',
        'message',
        // media_path / media_type: freeform-media-with-caption sends. Used by
        // SendWhatsAppMessage Branch 2 ($campaign->media_path !== null) for
        // sending images/videos with a caption inside an open 24h conversation
        // window — DIFFERENT from header_media_url above. No UI surface today;
        // populate via API/seeder if you need this path. Kept intentionally so
        // freeform sends remain possible without re-introducing a schema change.
        'media_path',
        'media_type',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'rate_per_minute',
        'delay_min',
        'delay_max',
        'total_contacts',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rate_per_minute' => 'integer',
            'total_contacts' => 'integer',
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'read_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whatsAppInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class, 'instance_id');
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    /**
     * True when the campaign should be sent via Meta's template API rather
     * than freeform text. Required for any outreach outside the 24h
     * conversation window (i.e. all marketing campaigns to fresh contacts).
     */
    public function shouldSendAsTemplate(): bool
    {
        return $this->message_template_id !== null;
    }

    public function contactGroups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'campaign_group', 'campaign_id', 'group_id');
    }

    // Alias for views that reference $campaign->groups
    public function groups(): BelongsToMany
    {
        return $this->contactGroups();
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }

    public function getDeliveryRateAttribute(): float
    {
        if ($this->sent_count > 0) {
            return ($this->delivered_count / $this->sent_count) * 100;
        }

        return 0;
    }

    public function getReadRateAttribute(): float
    {
        if ($this->delivered_count > 0) {
            return ($this->read_count / $this->delivered_count) * 100;
        }

        return 0;
    }
}
