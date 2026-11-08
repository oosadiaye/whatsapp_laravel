<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\LeadProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an admin is hunting for: a plain-English brief, hard filters that always
 * win over the AI, the platforms to search and a daily pull cap.
 */
class LeadProfile extends Model
{
    /** @use HasFactory<LeadProfileFactory> */
    use HasFactory, OwnedByUser;

    public function ownerColumn(): string
    {
        return 'created_by';
    }

    protected $fillable = [
        'name', 'is_active', 'brief', 'hard_filters', 'sources', 'lead_query',
        'daily_pull_cap', 'pull_state', 'last_pulled_at', 'last_pull_error', 'created_by',
        'hot_threshold', 'warm_threshold', 'daily_qualify_cap', 'monthly_reveal_budget', 'last_qualify_error',
        'autonomy', 'sender_email_account_id', 'playbook', 'followup_count', 'followup_delay_days', 'daily_send_cap', 'last_outreach_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hard_filters' => 'array',
            'sources' => 'array',
            'lead_query' => 'array',
            'daily_pull_cap' => 'integer',
            'pull_state' => 'array',
            'last_pulled_at' => 'datetime',
            'hot_threshold' => 'integer',
            'warm_threshold' => 'integer',
            'daily_qualify_cap' => 'integer',
            'monthly_reveal_budget' => 'integer',
            'followup_count' => 'integer',
            'followup_delay_days' => 'integer',
            'daily_send_cap' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function senderAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'sender_email_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasQuery(): bool
    {
        return ! empty($this->lead_query);
    }

    /**
     * @return array{next_page: int, exhausted: bool}
     */
    public function sourceState(string $source): array
    {
        $state = ($this->pull_state ?? [])[$source] ?? [];

        return [
            'next_page' => max(1, (int) ($state['next_page'] ?? 1)),
            'exhausted' => (bool) ($state['exhausted'] ?? false),
        ];
    }
}
