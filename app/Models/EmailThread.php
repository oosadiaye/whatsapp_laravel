<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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

    /** The newest message, eager-loadable so a list of threads costs one query, not one each. */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(EmailMessage::class)->latestOfMany();
    }

    /** One line of the newest message's text, for the list row. */
    public function getSnippet(int $length = 90): string
    {
        $message = $this->latestMessage;
        $raw = (string) ($message?->body_text ?: preg_replace('#<(style|script)\b.*?</\1>#is', ' ', (string) $message?->body_html));
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), $length);
    }

    /** Outlook-style stamp: the time today, the date this year, the full date before that. */
    public function getListDateLabel(): string
    {
        $at = $this->last_message_at;

        return match (true) {
            $at === null => '',
            $at->isToday() => $at->format('g:i A'),
            $at->isCurrentYear() => $at->format('M j'),
            default => $at->format('M j, Y'),
        };
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** Who the latest message we sent went to, for the Sent list. */
    public function getRecipientPreview(): ?string
    {
        $sent = $this->messages()->where('direction', EmailMessage::DIRECTION_OUTBOUND)->latest('id')->first();
        $to = collect($sent?->to ?? [])->filter()->values();

        return $to->isEmpty() ? null : $to->first().($to->count() > 1 ? ' +'.($to->count() - 1) : '');
    }

    public function getSenderPreview(): ?string
    {
        return $this->latestMessage?->from_email;
    }
}
