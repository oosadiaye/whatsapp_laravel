<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message thread within a connected mailbox (plan B1).
 */
class EmailThread extends Model
{
    /** @use HasFactory<EmailThreadFactory> */
    use HasFactory;

    public const FOLDER_INBOX = 'inbox';
    public const FOLDER_SENT = 'sent';
    public const FOLDER_ARCHIVE = 'archive';
    public const FOLDER_TRASH = 'trash';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_account_id',
        'subject',
        'last_message_at',
        'unread_count',
        'folder',
        'assigned_to_user_id',
        'thread_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
