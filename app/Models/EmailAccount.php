<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A per-employee connected mailbox (plan B1). Each row is one user's own email
 * account — the substrate for the two-way client, distinct from bulk campaigns.
 */
class EmailAccount extends Model
{
    /** @use HasFactory<EmailAccountFactory> */
    use HasFactory, SoftDeletes;

    public const PROVIDER_GMAIL = 'gmail';
    public const PROVIDER_GRAPH = 'graph';
    public const PROVIDER_IMAP = 'imap';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email',
        'provider',
        'display_name',
        'credentials',
        'sync_state',
        'last_synced_at',
        'is_active',
        'needs_reauth',
    ];

    /**
     * `credentials` holds live OAuth tokens / IMAP passwords. The `encrypted`
     * cast DECRYPTS on attribute access, so it MUST be hidden — otherwise
     * toArray()/JSON/Livewire serialization would emit the plaintext to the
     * browser. Mirrors WhatsAppInstance ($hidden access_token/app_secret). Never
     * bind this to a public Livewire property.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'sync_state' => 'array',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'needs_reauth' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Soft-delete-safe resolver. `email_accounts` combines softDeletes() with a
     * plain unique(user_id, email), so reconnecting a previously-disconnected
     * account must REVIVE the trashed row rather than collide on the (unversioned)
     * DB constraint. Same trap + fix as Contact::firstOrNewIncludingTrashed
     * (memory: contact-softdelete-unique-trap).
     *
     * @param  array<string, mixed>  $attributes  the unique lookup key
     */
    public static function firstOrNewIncludingTrashed(array $attributes): self
    {
        $account = static::withTrashed()->where($attributes)->first();

        if ($account === null) {
            return new self($attributes);
        }

        if ($account->trashed()) {
            $account->restore();
        }

        return $account;
    }
}
