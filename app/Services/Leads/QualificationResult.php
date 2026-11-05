<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Lead;

/** What judging one lead produced. `byAi` is false for free, code-decided rejections. */
final readonly class QualificationResult
{
    public function __construct(
        public string $tier,
        public ?int $score,
        public string $reason,
        public bool $byAi,
    ) {}

    public function passed(): bool
    {
        return $this->tier !== Lead::TIER_REJECTED;
    }
}
