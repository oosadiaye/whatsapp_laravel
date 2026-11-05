<?php

declare(strict_types=1);

namespace App\Services\Leads;

interface LeadSource
{
    /** Stable machine key, e.g. "apollo". Matches the key in config/leads.php. */
    public function key(): string;

    public function label(): string;

    /** True when credentials are present. */
    public function isConfigured(): bool;

    /**
     * Make a cheap call that proves the credentials work.
     *
     * @throws LeadSourceException
     */
    public function testConnection(): void;

    /**
     * @throws LeadSourceException
     */
    public function search(LeadQuery $query, int $page, int $perPage): LeadPage;

    /**
     * Fetch the contact details a search hides (usually spends the platform's credits).
     *
     * @throws LeadSourceException
     */
    public function reveal(LeadData $lead): LeadData;
}
