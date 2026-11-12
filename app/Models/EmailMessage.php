<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inbound or outbound message (plan B1). Deduped per account by message_id.
 */
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const SEND_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_thread_id',
        'email_account_id',
        'direction',
        'message_id',
        'in_reply_to',
        'references_header',
        'from_email',
        'to',
        'cc',
        'bcc',
        'subject',
        'body_html',
        'body_text',
        'is_read',
        'has_attachments',
        'send_status',
        'send_error',
        'provider_ref',
        'sent_at',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'is_read' => 'boolean',
            'has_attachments' => 'boolean',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function isSendFailure(): bool
    {
        return $this->send_status === self::SEND_FAILED;
    }

    /**
     * An automatic "your message could not be delivered" notice from a mail server: the recipient
     * address does not exist, the inbox is full, or the message was refused. It arrives as ordinary
     * inbound mail, so it is recognised by who sent it and what it says.
     */
    public function isBounce(): bool
    {
        if ($this->direction !== self::DIRECTION_INBOUND) {
            return false;
        }

        $from = strtolower((string) $this->from_email);
        $subject = strtolower((string) $this->subject);

        return (bool) preg_match('/mailer-daemon|postmaster|mail delivery subsystem/', $from)
            || (bool) preg_match('/undeliver|delivery status notification|delivery has failed|mail delivery failed|returned mail|failure notice|could ?n.?t be delivered|was ?n.?t delivered/', $subject);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
