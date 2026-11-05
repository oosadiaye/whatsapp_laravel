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
        $this->assertSame(['CEO', 'Founder'], $query->titles);
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
}
