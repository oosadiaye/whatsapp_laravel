<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadQueryException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeAiClient;

final class LeadQueryBuilderTest extends TestCase
{
    public function test_turns_the_ai_json_into_a_sanitised_query(): void
    {
        $ai = new FakeAiClient(json_encode([
            'titles' => ['Founder', 'CEO'],
            'seniorities' => ['c_suite', 'bogus'],
            'person_locations' => ['Lagos, Nigeria'],
            'keywords' => 'fintech',
        ]));

        $query = (new LeadQueryBuilder($ai))->build('Fintech founders in Lagos', []);

        $this->assertSame(['Founder', 'CEO'], $query->titles);
        $this->assertSame(['c_suite'], $query->seniorities);
        $this->assertSame(['Lagos, Nigeria'], $query->personLocations);
        $this->assertSame('fintech', $query->keywords);
    }

    public function test_tolerates_code_fences_around_the_json(): void
    {
        $ai = new FakeAiClient("```json\n{\"titles\":[\"CTO\"]}\n```");

        $this->assertSame(['CTO'], (new LeadQueryBuilder($ai))->build('CTOs', [])->titles);
    }

    public function test_hard_filters_win_over_the_ai(): void
    {
        $ai = new FakeAiClient(json_encode(['titles' => ['CEO', 'Intern'], 'person_locations' => ['Ghana']]));

        $query = (new LeadQueryBuilder($ai))->build('CEOs', [
            'countries' => ['Nigeria'],
            'titles_exclude' => ['Intern'],
            'titles_include' => ['Director'],
        ]);

        $this->assertSame(['Nigeria'], $query->personLocations);
        $this->assertSame(['Intern'], $query->excludeTitles);
        // Admin titles come first; an AI title that is also excluded is dropped.
        $this->assertSame(['Director', 'CEO'], $query->titles);
    }

    public function test_the_brief_is_sent_to_the_model(): void
    {
        $ai = new FakeAiClient(json_encode(['titles' => ['CEO']]));

        (new LeadQueryBuilder($ai))->build('Heads of procurement at hospitals', []);

        $this->assertStringContainsString('Heads of procurement at hospitals', $ai->calls[0][0]['content']);
    }

    public function test_a_failed_ai_call_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient(null)))->build('CEOs', []);
    }

    public function test_non_json_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient('Sure, here you go!')))->build('CEOs', []);
    }

    public function test_a_result_with_nothing_to_search_for_throws(): void
    {
        $this->expectException(LeadQueryException::class);

        (new LeadQueryBuilder(new FakeAiClient('{}')))->build('stuff', []);
    }
}
