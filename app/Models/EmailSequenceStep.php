<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSequenceStep extends Model
{
    protected $fillable = [
        'email_sequence_id',
        'order',
        'subject',
        'body_text',
        'body_html',
        'delay_hours',
        'delay_days',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'delay_hours' => 'integer',
            'delay_days' => 'integer',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(EmailSequence::class, 'email_sequence_id');
    }

    public function totalDelayHours(): int
    {
        return ($this->delay_days * 24) + $this->delay_hours;
    }
}
