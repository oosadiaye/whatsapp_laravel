<?php

declare(strict_types=1);

namespace App\Services\Leads;

/** One page of search results. */
final readonly class LeadPage
{
    /**
     * @param  list<LeadData>  $leads
     */
    public function __construct(
        public array $leads,
        public int $total,
        public bool $hasMore,
    ) {}
}
