<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a lead's timeline: what happened, to whom, and who or what did it. */
class LeadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'type', 'payload', 'actor'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(Lead $lead, string $type, array $payload = [], string $actor = 'system'): self
    {
        return static::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'actor' => $actor,
        ]);
    }
}
