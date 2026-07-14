<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The do-not-email set. Any address here is skipped by the send pipeline.
 * Emails are stored and matched lowercased so casing can't slip a suppressed
 * address back into a send.
 */
class EmailSuppression extends Model
{
    use HasFactory;

    public const REASON_UNSUBSCRIBE = 'unsubscribe';
    public const REASON_BOUNCE = 'bounce';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_MANUAL = 'manual';

    protected $fillable = ['email', 'reason'];

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function isSuppressed(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return static::query()->where('email', static::normalize($email))->exists();
    }

    /**
     * Idempotently add an address to the suppression list.
     */
    public static function suppress(string $email, string $reason = self::REASON_UNSUBSCRIBE): self
    {
        return static::firstOrCreate(
            ['email' => static::normalize($email)],
            ['reason' => $reason],
        );
    }
}
