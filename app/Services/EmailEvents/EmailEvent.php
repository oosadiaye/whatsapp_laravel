<?php

declare(strict_types=1);

namespace App\Services\EmailEvents;

use App\Models\EmailSuppression;

/**
 * A normalized, provider-agnostic email delivery event worth acting on — a hard
 * (permanent) bounce or a spam complaint. Soft/transient bounces are never
 * turned into one of these, so they can't strip a temporarily-undeliverable but
 * otherwise-valid address off a list.
 */
final class EmailEvent
{
    public const TYPE_BOUNCE = 'bounce';
    public const TYPE_COMPLAINT = 'complaint';

    public function __construct(
        public readonly string $email,
        public readonly string $type,
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * Map the event type onto the suppression-list reason.
     */
    public function suppressionReason(): string
    {
        return $this->type === self::TYPE_COMPLAINT
            ? EmailSuppression::REASON_COMPLAINT
            : EmailSuppression::REASON_BOUNCE;
    }
}
