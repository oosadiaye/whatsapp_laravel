<?php

declare(strict_types=1);

namespace App\Services\Leads\Outreach;

/** A validated email the AI wrote. Plain text; the sender turns it into HTML. */
final class Draft
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}
}
