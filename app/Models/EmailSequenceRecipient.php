<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSequenceRecipient extends Model
{
    protected $fillable = [
        'email_sequence_id',
        'contact_id',
        'email',
        'current_step',
        'status',
        'last_sent_at',
        'next_send_at',
        'completed_at',
        'open_count',
        'click_count',
        'reply_count',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'last_sent_at' => 'datetime',
            'next_send_at' => 'datetime',
            'completed_at' => 'datetime',
            'open_count' => 'integer',
            'click_count' => 'integer',
            'reply_count' => 'integer',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(EmailSequence::class, 'email_sequence_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
