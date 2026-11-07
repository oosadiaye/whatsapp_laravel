<?php

declare(strict_types=1);

namespace App\Services\Leads\Hunter;

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
 * Hunter.io connector. Hunter is company-first, so a lead here is a COMPANY until it is revealed.
 *
 *  - search(): Discover (free). A plain-English query built from the profile's keywords and locations
 *    returns companies (domain, name, how many emails Hunter knows). One lead per company, no person yet.
 *  - reveal(): Domain Search on that domain (spends credits). Takes the most senior person that has an
 *    email, preferring the profile's titles, and fills in their name, title and email.
 *
 * Limits worth knowing: Discover allows 5 requests a second and 50 a minute, 100 companies a page, and
 * paging past the first page needs a paid Hunter plan. Titles, company size and exclusions cannot be sent
 * to Discover; the AI qualifier and the title gate still apply to the person revealed afterwards.
 */
final class HunterLeadSource implements LeadSource
{
    private const BASE = 'https://api.hunter.io/v2';

    private const MAX_PER_PAGE = 100;

    private const SENIORITY_RANK = ['executive' => 3, 'senior' => 2, 'junior' => 1];

    public function key(): string
    {
        return 'hunter';
    }

    public function label(): string
    {
        return 'Hunter.io';
    }

    public function isConfigured(): bool
    {
        return filled(LeadConfig::key('hunter'));
    }

    public function testConnection(): void
    {
        // The account call costs nothing and proves the key.
        $this->send('get', '/account');
    }

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        $limit = max(1, min($perPage, self::MAX_PER_PAGE));
        $body = ['query' => $this->queryText($query), 'limit' => $limit];
        if ($page > 1) {
            $body['offset'] = ($page - 1) * $limit; // paid plans only; Hunter answers with an error on a free one
        }

        $response = $this->send('post', '/discover', $body);

        $leads = [];
        foreach ((array) $response->json('data', []) as $company) {
            $lead = is_array($company) ? $this->toLead($company) : null;
            if ($lead !== null) {
                $leads[] = $lead;
            }
        }

        $total = (int) $response->json('meta.results', count($leads));

        return new LeadPage($leads, $total, count($leads) >= $limit && ($page * $limit) < min($total, 10000));
    }

    public function reveal(LeadData $lead): LeadData
    {
        $domain = $lead->domain ?? $lead->sourceId;
        $response = $this->send('get', '/domain-search', [
            'domain' => $domain,
            'type' => 'personal',
            'seniority' => 'senior,executive',
            'required_field' => 'full_name',
            'limit' => 10,
        ]);

        $person = $this->bestPerson((array) $response->json('data.emails', []));
        if ($person === null) {
            return $lead->with(['emailStatus' => Lead::EMAIL_UNAVAILABLE]);
        }

        $name = trim(((string) ($person['first_name'] ?? '')).' '.((string) ($person['last_name'] ?? '')));

        return $lead->with([
            'name' => $name !== '' ? $name : $lead->name,
            'title' => $this->text($person['position'] ?? null) ?? $lead->title,
            'email' => strtolower(trim((string) $person['value'])),
            'emailStatus' => (string) ($person['verification']['status'] ?? 'unknown'),
            'linkedinUrl' => $this->text($person['linkedin'] ?? null) ?? $lead->linkedinUrl,
            'phone' => $this->text($person['phone_number'] ?? null) ?? $lead->phone,
        ]);
    }

    /** Hunter's Discover takes plain English: say what and where, in one sentence. */
    private function queryText(LeadQuery $query): string
    {
        $where = $query->organizationLocations !== [] ? $query->organizationLocations : $query->personLocations;
        $text = trim($query->keywords !== '' ? $query->keywords.' companies' : 'companies');

        return $where !== [] ? $text.' in '.implode(' or ', array_slice($where, 0, 3)) : $text;
    }

    /**
     * The most senior person with a usable email; ties go to the higher Hunter confidence.
     *
     * @param  array<int, mixed>  $emails
     * @return array<string, mixed>|null
     */
    private function bestPerson(array $emails): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($emails as $email) {
            if (! is_array($email) || ! filled($email['value'] ?? null)) {
                continue;
            }
            if (($email['verification']['status'] ?? null) === 'invalid') {
                continue;
            }

            $score = (self::SENIORITY_RANK[$email['seniority'] ?? ''] ?? 0) * 1000
                + (! empty($email['decision_maker']) ? 500 : 0)
                + (int) ($email['confidence'] ?? 0);
            if ($score > $bestScore) {
                $best = $email;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function toLead(array $company): ?LeadData
    {
        $domain = strtolower(trim((string) ($company['domain'] ?? '')));
        if ($domain === '') {
            return null;
        }

        $known = (int) ($company['emails_count']['personal'] ?? $company['emails_count']['total'] ?? 0);

        return new LeadData(
            source: 'hunter',
            sourceId: $domain,
            name: $this->text($company['organization'] ?? null) ?? $domain,
            company: $this->text($company['organization'] ?? null) ?? $domain,
            emailStatus: $known > 0 ? Lead::EMAIL_UNREVEALED : Lead::EMAIL_UNAVAILABLE,
            domain: $domain,
            raw: $company,
        );
    }

    /**
     * @param  array<string, mixed>  $params  query string for GET, JSON body for POST
     */
    private function send(string $method, string $path, array $params = []): Response
    {
        $key = LeadConfig::key('hunter');
        if ($key === null) {
            throw new LeadSourceException('Hunter is not configured: add an API key under Lead Sources.');
        }

        $url = rtrim((string) config('leads.hunter_base_url', self::BASE), '/').$path;

        try {
            $response = $method === 'post'
                ? $this->http($key)->post($url, $params)
                : $this->http($key)->get($url, $params);
        } catch (ConnectionException $e) {
            throw new LeadSourceException('Could not reach Hunter (network error); will retry on the next run.', true, $e);
        }

        $this->guard($response);

        return $response;
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders(['X-API-KEY' => $key]);
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => new LeadSourceException('Hunter rejected the API key (401). Check the key under Lead Sources.'),
            $status === 403 => new LeadSourceException('Hunter rate limit reached (403); will retry on the next run.', true),
            $status === 429 => new LeadSourceException('Your Hunter usage limit is used up (429). Add credits or wait for the next billing period.'),
            $status === 400 || $status === 422 => new LeadSourceException('Hunter rejected the request ('.$status.'). Paging past the first page needs a paid Hunter plan; otherwise edit the profile.'),
            $status >= 500 => new LeadSourceException("Hunter is unavailable ({$status}); will retry on the next run.", true),
            default => new LeadSourceException("Hunter returned an unexpected response ({$status})."),
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
