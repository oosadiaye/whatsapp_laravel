<?php

declare(strict_types=1);

namespace App\Services\Leads\Lusha;

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
 * Lusha connector (API v3).
 *
 *  - search(): POST /contacts/prospecting. Returns people previews (name, title, company, and which data
 *    points Lusha can reveal) filtered by job titles, locations and company size. Page numbers are 0-based
 *    on Lusha's side; sizes are 10 to 100.
 *  - reveal(): POST /contacts/enrich for the one contact, asking for the email only (phones are not needed
 *    and cost extra). Charged per revealed field.
 *
 * Not sent to Lusha: seniority and industry (their values are account-specific ids and labels, looked up
 * through a separate endpoint) and excluded titles. The AI qualifier and the title gate cover those.
 */
final class LushaLeadSource implements LeadSource
{
    private const BASE = 'https://api.lusha.com/v3';

    private const MIN_PAGE_SIZE = 10;

    private const MAX_PAGE_SIZE = 100;

    private const MAX_PAGE_INDEX = 1000;

    public function key(): string
    {
        return 'lusha';
    }

    public function label(): string
    {
        return 'Lusha';
    }

    public function isConfigured(): bool
    {
        return filled(LeadConfig::key('lusha'));
    }

    public function testConnection(): void
    {
        // Listing a filter's values proves the key and the v3 access without spending credits.
        $this->send('get', '/contacts/prospecting/filters/seniority');
    }

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        $size = max(self::MIN_PAGE_SIZE, min($perPage, self::MAX_PAGE_SIZE));
        $index = max(0, $page - 1);

        $response = $this->send('post', '/contacts/prospecting', [
            'pagination' => ['page' => $index, 'size' => $size],
            'filters' => $this->filters($query),
            'options' => ['includePartialProfiles' => true],
        ]);

        $leads = [];
        foreach ((array) $response->json('results', []) as $person) {
            $lead = is_array($person) ? $this->toLead($person) : null;
            if ($lead !== null) {
                $leads[] = $lead;
            }
        }

        $total = (int) $response->json('pagination.total', count($leads));

        return new LeadPage($leads, $total, $leads !== [] && $index < self::MAX_PAGE_INDEX && ($index + 1) * $size < $total);
    }

    public function reveal(LeadData $lead): LeadData
    {
        $response = $this->send('post', '/contacts/enrich', ['ids' => [$lead->sourceId], 'reveal' => ['emails']]);

        if ($response->json('job') !== null) {
            // Lusha is still working on it; the lead stays unrevealed and the next run asks again.
            throw new LeadSourceException('Lusha is still preparing this contact; will retry on the next run.', true);
        }

        $contact = $this->contactIn((array) $response->json('results', []), $lead->sourceId);
        $email = $this->bestEmail((array) ($contact['emails'] ?? []));
        if ($email === null) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        return $lead->with([
            'email' => strtolower(trim((string) $email['email'])),
            'emailStatus' => $this->statusOf($email),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(LeadQuery $query): array
    {
        $contactLocations = $this->locations($query->personLocations);
        $companyLocations = $this->locations($query->organizationLocations);
        $sizes = $this->sizes($query->employeeRanges);

        $contacts = array_filter(['jobTitles' => $query->titles, 'locations' => $contactLocations]);
        $companies = array_filter(['sizes' => $sizes, 'locations' => $companyLocations]);

        return [
            'contacts' => ['include' => (object) $contacts, 'exclude' => (object) []],
            'companies' => ['include' => (object) $companies, 'exclude' => (object) []],
        ];
    }

    /**
     * "Lagos, Nigeria" becomes city and country; a single word is taken as a country.
     *
     * @param  list<string>  $places
     * @return list<array<string, string>>
     */
    private function locations(array $places): array
    {
        $out = [];
        foreach ($places as $place) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $place)), fn (string $part): bool => $part !== ''));
            if ($parts === []) {
                continue;
            }
            $out[] = count($parts) === 1
                ? ['country' => $parts[0]]
                : ['country' => $parts[count($parts) - 1], 'city' => $parts[0]];
        }

        return $out;
    }

    /**
     * @param  list<string>  $ranges  "min,max"
     * @return list<array{min: int, max: int}>
     */
    private function sizes(array $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            [$min, $max] = array_pad(array_map('intval', explode(',', $range)), 2, 0);
            if ($max >= $min && $max > 0) {
                $out[] = ['min' => $min, 'max' => $max];
            }
        }

        return $out;
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

        $name = trim(((string) ($person['firstName'] ?? '')).' '.((string) ($person['lastName'] ?? '')));
        $company = is_array($person['company'] ?? null) ? $person['company'] : [];
        $canEmail = in_array('emails', (array) ($person['has'] ?? []), true) || $this->canReveal($person, 'emails');

        return new LeadData(
            source: 'lusha',
            sourceId: $id,
            name: $name !== '' ? $name : 'Unknown',
            title: $this->text($person['jobTitle']['title'] ?? null),
            company: $this->text($company['name'] ?? null),
            emailStatus: $canEmail ? Lead::EMAIL_UNREVEALED : Lead::EMAIL_UNAVAILABLE,
            domain: ($d = $this->text($company['domain'] ?? null)) !== null ? strtolower($d) : null,
            country: $this->text($person['location']['country'] ?? null),
            linkedinUrl: $this->text($person['socialLinks']['linkedin'] ?? null),
            raw: $person,
        );
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function canReveal(array $person, string $field): bool
    {
        foreach ((array) ($person['canReveal'] ?? []) as $item) {
            if (is_array($item) && ($item['field'] ?? null) === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $results
     * @return array<string, mixed>
     */
    private function contactIn(array $results, string $id): array
    {
        foreach ($results as $result) {
            if (is_array($result) && (string) ($result['id'] ?? '') === $id) {
                return $result;
            }
        }

        return is_array($results[0] ?? null) ? $results[0] : [];
    }

    /**
     * Work emails first, then the most confident one.
     *
     * @param  array<int, mixed>  $emails
     * @return array<string, mixed>|null
     */
    private function bestEmail(array $emails): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($emails as $email) {
            if (! is_array($email) || ! filled($email['email'] ?? null)) {
                continue;
            }
            $score = (($email['type'] ?? '') === 'work' ? 1000 : 0) + (int) ($email['confidence'] ?? 0);
            if ($score > $bestScore) {
                $best = $email;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function statusOf(array $email): string
    {
        $confidence = $email['confidence'] ?? null;

        return is_string($confidence) && $confidence !== '' ? strtolower($confidence) : (is_numeric($confidence) ? 'confidence_'.(int) $confidence : 'unknown');
    }

    /**
     * @param  array<string, mixed>  $params  query string for GET, JSON body for POST
     */
    private function send(string $method, string $path, array $params = []): Response
    {
        $key = LeadConfig::key('lusha');
        if ($key === null) {
            throw new LeadSourceException('Lusha is not configured: add an API key under Lead Sources.');
        }

        $url = rtrim((string) config('leads.lusha_base_url', self::BASE), '/').$path;

        try {
            $response = $method === 'post'
                ? $this->http($key)->post($url, $params)
                : $this->http($key)->get($url, $params);
        } catch (ConnectionException $e) {
            throw new LeadSourceException('Could not reach Lusha (network error); will retry on the next run.', true, $e);
        }

        $this->guard($response);

        return $response;
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders(['api_key' => $key]);
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => new LeadSourceException('Lusha rejected the API key (401). Check the key under Lead Sources.'),
            $status === 402 => new LeadSourceException('Lusha says there are no credits left (402). Add credits to continue.'),
            $status === 403 => new LeadSourceException('This Lusha account is inactive or does not have the v3 API enabled (403).'),
            $status === 400 => new LeadSourceException('Lusha rejected the search (400). Edit the profile and rebuild the search.'),
            $status === 429 => new LeadSourceException('Lusha rate limit or daily quota reached (429); will retry on the next run.', true),
            $status >= 500 => new LeadSourceException("Lusha is unavailable ({$status}); will retry on the next run.", true),
            default => new LeadSourceException("Lusha returned an unexpected response ({$status})."),
        };
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
