<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message attachment (plan B1). Binary lives on the private disk.
 */
class EmailAttachment extends Model
{
    /** @use HasFactory<EmailAttachmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_message_id',
        'filename',
        'mime',
        'size',
        'path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
