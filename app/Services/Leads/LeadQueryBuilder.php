<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Services\Ai\AiClient;

/**
 * Turns an admin's plain-English brief into a structured LeadQuery once, when the
 * profile is saved. Pulls then run with no AI cost, and the admin can read the
 * result. Hard filters are applied in code afterwards so they can never be
 * overridden by the model.
 */
final class LeadQueryBuilder
{
    public function __construct(private readonly AiClient $ai) {}

    /**
     * @param  array<string, mixed>  $hardFilters
     *
     * @throws LeadQueryException
     */
    public function build(string $brief, array $hardFilters): LeadQuery
    {
        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->prompt($brief)]],
            opts: ['tier' => 'classify', 'temperature' => 0.1, 'max_tokens' => 600],
        );

        if ($result === null) {
            throw new LeadQueryException('The AI provider is not configured or did not answer. Check Settings, then rebuild the search.');
        }

        $data = $this->decode($result->text);
        $query = LeadQuery::fromArray($data)->withHardFilters($hardFilters);

        if ($query->isEmpty()) {
            throw new LeadQueryException('Could not derive any search filters from that brief. Add job titles, a location or an industry.');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $match) !== 1) {
            throw new LeadQueryException('The AI did not return search filters. Try rebuilding.');
        }

        $data = json_decode($match[0], true);

        if (! is_array($data)) {
            throw new LeadQueryException('The AI returned filters that could not be read. Try rebuilding.');
        }

        return $data;
    }

    private function prompt(string $brief): string
    {
        return <<<PROMPT
You turn a sales-lead brief into search filters for a B2B contact database.
Reply with ONE JSON object and nothing else. Every key is optional; values are arrays of short strings unless stated.
- "titles": job titles to match, e.g. ["CEO","Managing Director"]
- "exclude_titles": job titles to exclude
- "seniorities": any of owner, founder, c_suite, partner, vp, head, director, manager, senior, entry, intern
- "person_locations": where the person is based, e.g. ["Lagos, Nigeria"]
- "organization_locations": where the company is headquartered
- "employee_ranges": strings "min,max", e.g. ["11,50","51,200"]
- "keywords": ONE short string of industry or topic words (not an array)
Only include what the brief clearly asks for. Do not invent constraints.

BRIEF:
{$brief}
PROMPT;
    }
}
