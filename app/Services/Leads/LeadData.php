<?php

declare(strict_types=1);

namespace App\Services\Leads;

/** A lead as a platform describes it, normalised so every connector returns the same shape. */
final readonly class LeadData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $name,
        public ?string $title = null,
        public ?string $company = null,
        public ?string $email = null,
        public string $emailStatus = 'unrevealed',
        public ?string $domain = null,
        public ?string $country = null,
        public ?string $phone = null,
        public ?string $linkedinUrl = null,
        public array $raw = [],
    ) {}

    /**
     * A copy with some fields replaced (this object is immutable).
     *
     * @param  array<string, mixed>  $changes  keyed by property name
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
