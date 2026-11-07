<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Services\Leads\LeadQuery;
use PHPUnit\Framework\TestCase;

final class LeadQueryTest extends TestCase
{
    public function test_from_array_trims_dedupes_and_drops_junk(): void
    {
        $query = LeadQuery::fromArray([
            'titles' => [' CEO ', 'ceo', '', 42, 'Founder'],
            'seniorities' => ['C_Suite', 'wizard', 'founder'],
            'employee_ranges' => ['11,50', 'lots', '51,200'],
            'keywords' => '  fintech  ',
        ]);

        $this->assertSame(['CEO', 'Founder'], $query->titles);
        $this->assertSame(['c_suite', 'founder'], $query->seniorities);
        $this->assertSame(['11,50', '51,200'], $query->employeeRanges);
        $this->assertSame('fintech', $query->keywords);
    }

    public function test_from_array_caps_list_length(): void
    {
        $titles = array_map(fn (int $i) => "Title $i", range(1, 80));

        $this->assertCount(30, LeadQuery::fromArray(['titles' => $titles])->titles);
    }

    public function test_hard_filters_override_locations_and_ranges_and_add_titles(): void
    {
        $query = LeadQuery::fromArray([
            'titles' => ['CEO'],
            'person_locations' => ['Ghana'],
            'employee_ranges' => ['1,10'],
        ])->withHardFilters([
            'countries' => ['Nigeria'],
            'titles_include' => ['Founder'],
            'titles_exclude' => ['Intern'],
            'employee_ranges' => ['11,50'],
            'require_verified_email' => true,
        ]);

        $this->assertSame(['Nigeria'], $query->personLocations);
        $this->assertSame(['Founder', 'CEO'], $query->titles);
        $this->assertSame(['Intern'], $query->excludeTitles);
        $this->assertSame(['11,50'], $query->employeeRanges);
        $this->assertTrue($query->requireVerifiedEmail);
    }

    public function test_hard_filters_that_are_empty_leave_the_ai_values_alone(): void
    {
        $query = LeadQuery::fromArray(['person_locations' => ['Ghana']])->withHardFilters([]);

        $this->assertSame(['Ghana'], $query->personLocations);
    }

    public function test_is_empty_and_round_trip(): void
    {
        $this->assertTrue((new LeadQuery)->isEmpty());

        $query = LeadQuery::fromArray(['titles' => ['CEO'], 'require_verified_email' => true]);
        $this->assertFalse($query->isEmpty());
        $this->assertEquals($query, LeadQuery::fromArray($query->toArray()));
    }

    public function test_keywords_accepts_string_or_array_and_never_throws(): void
    {
        $this->assertSame('fintech payments', LeadQuery::fromArray(['keywords' => ['fintech', 'payments', 7]])->keywords);
        $this->assertSame('', LeadQuery::fromArray(['keywords' => 123])->keywords);
        $this->assertSame('', LeadQuery::fromArray(['keywords' => null])->keywords);
    }

    public function test_require_verified_email_parses_booleans_safely(): void
    {
        $this->assertFalse(LeadQuery::fromArray(['require_verified_email' => 'false'])->requireVerifiedEmail);
        $this->assertFalse(LeadQuery::fromArray(['require_verified_email' => '0'])->requireVerifiedEmail);
        $this->assertTrue(LeadQuery::fromArray(['require_verified_email' => 'true'])->requireVerifiedEmail);
        $this->assertTrue(LeadQuery::fromArray(['require_verified_email' => '1'])->requireVerifiedEmail);
        $this->assertFalse(LeadQuery::fromArray(['require_verified_email' => ['x']])->requireVerifiedEmail);

        $hard = new LeadQuery;
        $this->assertFalse($hard->withHardFilters(['require_verified_email' => 'false'])->requireVerifiedEmail);
        $this->assertTrue($hard->withHardFilters(['require_verified_email' => 'true'])->requireVerifiedEmail);
        $this->assertFalse($hard->withHardFilters(['require_verified_email' => ['x']])->requireVerifiedEmail);
    }

    public function test_admin_titles_survive_the_item_cap(): void
    {
        $titles = array_map(fn (int $i) => "Title $i", range(1, 30));

        $query = LeadQuery::fromArray(['titles' => $titles])->withHardFilters(['titles_include' => ['Founder']]);

        $this->assertContains('Founder', $query->titles);
        $this->assertCount(30, $query->titles);
        $this->assertSame('Founder', $query->titles[0]);
    }

    public function test_admin_exclusions_survive_the_item_cap(): void
    {
        $excluded = array_map(fn (int $i) => "Skip $i", range(1, 30));

        $query = LeadQuery::fromArray(['exclude_titles' => $excluded])->withHardFilters(['titles_exclude' => ['Intern']]);

        $this->assertContains('Intern', $query->excludeTitles);
        $this->assertCount(30, $query->excludeTitles);
    }

    public function test_excluded_titles_are_removed_from_titles_case_insensitively(): void
    {
        $query = LeadQuery::fromArray(['titles' => ['CEO', 'Intern']])
            ->withHardFilters(['titles_exclude' => ['intern']]);

        $this->assertSame(['CEO'], $query->titles);
        $this->assertSame(['intern'], $query->excludeTitles);
    }

    public function test_dedupe_happens_after_truncation(): void
    {
        $base = str_repeat('a', 80);

        $query = LeadQuery::fromArray(['titles' => [$base.'X'.str_repeat('b', 19), $base.'Y'.str_repeat('c', 19)]]);

        $this->assertCount(1, $query->titles);
        $this->assertSame($base, $query->titles[0]);
    }

    public function test_ranges_require_min_not_above_max(): void
    {
        $query = LeadQuery::fromArray(['employee_ranges' => ['50,10', '10,50', '5,5']]);

        $this->assertSame(['10,50', '5,5'], $query->employeeRanges);
    }
}
