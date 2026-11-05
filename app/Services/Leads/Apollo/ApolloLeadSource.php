<?php

declare(strict_types=1);

namespace App\Services\Leads\Apollo;

use App\Models\Lead;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadPage;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSource;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Apollo.io connector.
 *
 * Search (mixed_people/api_search) is free but returns only a first name, an
 * obfuscated last name, title and company, never an email. reveal() (people/match)
 * returns the email and costs about one credit, so callers decide when to spend.
 * Parameters travel in the query string with array keys written `name[]=value`.
 */
final class ApolloLeadSource implements LeadSource
{
    private const BASE = 'https://api.apollo.io/api/v1';

    /** Apollo caps search results at 500 pages of 100. */
    private const MAX_RESULTS = 50000;

    public function key(): string
    {
        return 'apollo';
    }

    public function label(): string
    {
        return 'Apollo.io';
    }

    public function isConfigured(): bool
    {
        return filled(LeadConfig::key('apollo'));
    }

    public function testConnection(): void
    {
        // A one-row search: free, and exercises exactly the permission the pull needs.
        $this->search(new LeadQuery(titles: ['CEO']), 1, 1);
    }

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        $response = $this->post('/mixed_people/api_search', $this->searchParams($query, $page, $perPage));

        $people = (array) $response->json('people', []);
        $leads = [];
        foreach ($people as $person) {
            $lead = is_array($person) ? $this->toLead($person) : null;
            if ($lead !== null) {
                $leads[] = $lead;
            }
        }

        $total = min((int) $response->json('total_entries', count($leads)), self::MAX_RESULTS);

        return new LeadPage($leads, $total, $people !== [] && $page * $perPage < $total);
    }

    public function reveal(LeadData $lead): LeadData
    {
        $response = $this->post('/people/match', [
            'id' => $lead->sourceId,
            'reveal_personal_emails' => false,
        ]);

        $person = $response->json('person');
        if (! is_array($person)) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        $email = $person['email'] ?? null;
        if (! is_string($email) || trim($email) === '' || str_contains($email, 'not_unlocked')) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        $organization = is_array($person['organization'] ?? null) ? $person['organization'] : [];

        return $lead->with([
            'name' => $this->text($person['name'] ?? null) ?? $lead->name,
            'title' => $this->text($person['title'] ?? null) ?? $lead->title,
            'company' => $this->text($organization['name'] ?? null) ?? $lead->company,
            'email' => strtolower(trim($email)),
            'emailStatus' => (string) ($person['email_status'] ?? 'unknown'),
            'domain' => $this->domainOf($organization),
            'country' => $this->text($person['country'] ?? null),
            'linkedinUrl' => $this->text($person['linkedin_url'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchParams(LeadQuery $query, int $page, int $perPage): array
    {
        return [
            'person_titles' => $query->titles,
            'include_similar_titles' => $query->titles !== [] ? true : null,
            'person_not_titles' => $query->excludeTitles,
            'person_seniorities' => $query->seniorities,
            'person_locations' => $query->personLocations,
            'organization_locations' => $query->organizationLocations,
            'organization_num_employees_ranges' => $query->employeeRanges,
            'q_keywords' => $query->keywords,
            'contact_email_status' => $query->requireVerifiedEmail ? ['verified'] : [],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function toLead(array $person): ?LeadData
    {
        $id = (string) ($person['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = trim(((string) ($person['first_name'] ?? '')).' '.((string) ($person['last_name_obfuscated'] ?? $person['last_name'] ?? '')));

        return new LeadData(
            source: 'apollo',
            sourceId: $id,
            name: $name !== '' ? $name : 'Unknown',
            title: $this->text($person['title'] ?? null),
            company: $this->text($person['organization']['name'] ?? null),
            emailStatus: ($person['has_email'] ?? false) ? Lead::EMAIL_UNREVEALED : Lead::EMAIL_UNAVAILABLE,
            raw: $person,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $path, array $params): Response
    {
        $key = LeadConfig::key('apollo');
        if ($key === null) {
            throw new LeadSourceException('Apollo is not configured: add an API key under Lead Sources.');
        }

        try {
            $response = $this->http($key)->post(self::BASE.$path.'?'.$this->queryString($params));
        } catch (ConnectionException $e) {
            throw new LeadSourceException('Could not reach Apollo: '.$e->getMessage(), true, $e);
        }

        $this->guard($response);

        return $response;
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders([
            'x-api-key' => $key,
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => new LeadSourceException('Apollo rejected the API key (401). Check the key under Lead Sources.'),
            $status === 403 => new LeadSourceException('This Apollo key is not allowed to use people search (403). Use a master API key.'),
            $status === 422 => new LeadSourceException('Apollo rejected the search filters (422). Edit the profile and rebuild the search.'),
            $status === 429 => new LeadSourceException('Apollo rate limit reached (429); will retry on the next run.', true),
            $status >= 500 => new LeadSourceException("Apollo is unavailable ({$status}); will retry on the next run.", true),
            default => new LeadSourceException("Apollo returned an unexpected response ({$status})."),
        };
    }

    /**
     * Apollo wants arrays as `name[]=a&name[]=b`; Guzzle's own builder would index them.
     *
     * @param  array<string, mixed>  $params
     */
    private function queryString(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = $key.'[]='.rawurlencode((string) $item);
                }

                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = $key.'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $organization
     */
    private function domainOf(array $organization): ?string
    {
        $domain = $this->text($organization['primary_domain'] ?? null);
        if ($domain !== null) {
            return strtolower($domain);
        }

        $host = parse_url((string) ($organization['website_url'] ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(preg_replace('/^www\./i', '', $host)) : null;
    }
}
