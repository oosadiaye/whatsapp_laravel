<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * The structured search a profile runs. Built once from the brief (AI) plus the
 * hard filters (code) and stored on the profile so an admin can read and trust it.
 * Everything is sanitised here: the AI's output is untrusted.
 */
final readonly class LeadQuery
{
    public const SENIORITIES = [
        'owner', 'founder', 'c_suite', 'partner', 'vp', 'head', 'director', 'manager', 'senior', 'entry', 'intern',
    ];

    private const MAX_ITEMS = 30;

    private const MAX_LENGTH = 80;

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $excludeTitles
     * @param  list<string>  $seniorities
     * @param  list<string>  $personLocations
     * @param  list<string>  $organizationLocations
     * @param  list<string>  $employeeRanges  "min,max" strings
     */
    public function __construct(
        public array $titles = [],
        public array $excludeTitles = [],
        public array $seniorities = [],
        public array $personLocations = [],
        public array $organizationLocations = [],
        public array $employeeRanges = [],
        public string $keywords = '',
        public bool $requireVerifiedEmail = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            titles: self::strings($data['titles'] ?? []),
            excludeTitles: self::strings($data['exclude_titles'] ?? []),
            seniorities: array_values(array_intersect(
                array_map('mb_strtolower', self::strings($data['seniorities'] ?? [])),
                self::SENIORITIES,
            )),
            personLocations: self::strings($data['person_locations'] ?? []),
            organizationLocations: self::strings($data['organization_locations'] ?? []),
            employeeRanges: self::ranges($data['employee_ranges'] ?? []),
            keywords: self::keywords($data['keywords'] ?? ''),
            requireVerifiedEmail: self::bool($data['require_verified_email'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'titles' => $this->titles,
            'exclude_titles' => $this->excludeTitles,
            'seniorities' => $this->seniorities,
            'person_locations' => $this->personLocations,
            'organization_locations' => $this->organizationLocations,
            'employee_ranges' => $this->employeeRanges,
            'keywords' => $this->keywords,
            'require_verified_email' => $this->requireVerifiedEmail,
        ];
    }

    /**
     * Apply the admin's hard filters. They always win over what the AI inferred:
     * countries and employee ranges replace, titles are added, exclusions are added.
     *
     * @param  array<string, mixed>  $filters
     */
    public function withHardFilters(array $filters): self
    {
        $countries = self::strings($filters['countries'] ?? []);
        $ranges = self::ranges($filters['employee_ranges'] ?? []);

        // Admin items go first so the item cap can never drop them.
        $excludeTitles = self::strings([...self::strings($filters['titles_exclude'] ?? []), ...$this->excludeTitles]);
        $excluded = array_map('mb_strtolower', $excludeTitles);
        $titles = array_filter(
            [...self::strings($filters['titles_include'] ?? []), ...$this->titles],
            fn (string $title): bool => ! in_array(mb_strtolower($title), $excluded, true),
        );

        return new self(
            titles: self::strings($titles),
            excludeTitles: $excludeTitles,
            seniorities: $this->seniorities,
            personLocations: $countries !== [] ? $countries : $this->personLocations,
            organizationLocations: $this->organizationLocations,
            employeeRanges: $ranges !== [] ? $ranges : $this->employeeRanges,
            keywords: $this->keywords,
            requireVerifiedEmail: $this->requireVerifiedEmail || self::bool($filters['require_verified_email'] ?? false),
        );
    }

    /** True when there is nothing to search for (a search would match the whole database). */
    public function isEmpty(): bool
    {
        return $this->titles === []
            && $this->seniorities === []
            && $this->personLocations === []
            && $this->organizationLocations === []
            && $this->employeeRanges === []
            && $this->keywords === '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $item = mb_substr($item, 0, self::MAX_LENGTH);
            $out[mb_strtolower($item)] ??= $item;
        }

        return array_slice(array_values($out), 0, self::MAX_ITEMS);
    }

    /** Keywords arrive as a string or a list of strings; anything else is dropped. */
    private static function keywords(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter($value, 'is_string'));
        }

        return is_string($value) ? mb_substr(trim($value), 0, 200) : '';
    }

    /** Tolerant boolean: "false" and "0" are false, "true" and "1" are true, junk is false. */
    private static function bool(mixed $value): bool
    {
        if (is_array($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @return list<string>
     */
    private static function ranges(mixed $value): array
    {
        return array_values(array_filter(
            self::strings($value),
            function (string $range): bool {
                if (preg_match('/^(\d+),(\d+)$/', $range, $m) !== 1) {
                    return false;
                }

                return (int) $m[1] <= (int) $m[2];
            },
        ));
    }
}
