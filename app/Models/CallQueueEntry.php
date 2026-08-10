<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallQueueEntry extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_LEFT = 'left';

    public const STATUS_TIMEOUT = 'timeout';

    protected $fillable = [
        'session_id',
        'caller_number',
        'queue_name',
        'position',
        'status',
        'entered_at',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'connected_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function scopeWaiting($q)
    {
        return $q->where('status', self::STATUS_WAITING)->orderBy('entered_at');
    }
}
