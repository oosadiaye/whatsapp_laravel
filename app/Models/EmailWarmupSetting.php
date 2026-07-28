<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailWarmupSetting extends Model
{
    protected $fillable = [
        'email_account_id',
        'enabled',
        'target_email',
        'daily_target',
        'last_warmup_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_target' => 'integer',
            'last_warmup_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }
}
