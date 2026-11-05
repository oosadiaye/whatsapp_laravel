<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Services\Leads\Apollo\ApolloLeadSource;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ApolloLeadSourceTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    private const MATCH = 'https://api.apollo.io/api/v1/people/match*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
    }

    private function source(): ApolloLeadSource
    {
        return new ApolloLeadSource;
    }

    private function person(array $overrides = []): array
    {
        return array_merge([
            'id' => 'p1',
            'first_name' => 'Ada',
            'last_name_obfuscated' => 'Ob***i',
            'title' => 'CEO',
            'has_email' => true,
            'organization' => ['name' => 'Acme Pay'],
        ], $overrides);
    }

    public function test_search_normalises_people_and_never_invents_an_email(): void
    {
        Http::fake([self::SEARCH => Http::response([
            'total_entries' => 60,
            'people' => [$this->person(), $this->person(['id' => 'p2', 'has_email' => false, 'title' => null])],
        ])]);

        $page = $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);

        $this->assertCount(2, $page->leads);
        $this->assertSame(60, $page->total);
        $this->assertTrue($page->hasMore);

        $first = $page->leads[0];
        $this->assertSame('apollo', $first->source);
        $this->assertSame('p1', $first->sourceId);
        $this->assertSame('Ada Ob***i', $first->name);
        $this->assertSame('Acme Pay', $first->company);
        $this->assertNull($first->email);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $first->emailStatus);

        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $page->leads[1]->emailStatus);
        $this->assertNull($page->leads[1]->title);
    }

    public function test_search_sends_filters_as_bracketed_query_params_with_the_api_key_header(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 0, 'people' => []])]);

        $this->source()->search(new LeadQuery(
            titles: ['CEO', 'Founder'],
            excludeTitles: ['Intern'],
            seniorities: ['c_suite'],
            personLocations: ['Lagos, Nigeria'],
            employeeRanges: ['11,50'],
            keywords: 'fintech',
            requireVerifiedEmail: true,
        ), 2, 25);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'POST'
                && ($request->header('x-api-key')[0] ?? null) === 'apollo-test-key-123'
                && str_contains($url, 'person_titles[]=CEO')
                && str_contains($url, 'person_titles[]=Founder')
                && str_contains($url, 'person_not_titles[]=Intern')
                && str_contains($url, 'person_seniorities[]=c_suite')
                && str_contains($url, 'person_locations[]=Lagos, Nigeria')
                && str_contains($url, 'organization_num_employees_ranges[]=11,50')
                && str_contains($url, 'q_keywords=fintech')
                && str_contains($url, 'contact_email_status[]=verified')
                && str_contains($url, 'page=2')
                && str_contains($url, 'per_page=25');
        });
    }

    public function test_has_more_is_false_on_the_last_page(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 60, 'people' => [$this->person()]])]);

        $page = $this->source()->search(new LeadQuery(titles: ['CEO']), 3, 25);

        $this->assertFalse($page->hasMore);
    }

    public function test_has_more_is_false_at_apollos_500_page_cap(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100000, 'people' => [$this->person()]])]);

        $this->assertFalse($this->source()->search(new LeadQuery(titles: ['CEO']), 500, 25)->hasMore);
        $this->assertTrue($this->source()->search(new LeadQuery(titles: ['CEO']), 499, 25)->hasMore);
    }

    public function test_reveal_keeps_existing_details_the_response_omits(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => [
            'email' => 'ada@acmepay.test',
            'email_status' => 'verified',
            'organization' => ['name' => 'Acme Pay'],
        ]])]);

        $lead = new LeadData(
            source: 'apollo',
            sourceId: 'p1',
            name: 'Ada',
            domain: 'acmepay.test',
            country: 'Nigeria',
            linkedinUrl: 'https://linkedin.com/in/adaobi',
        );

        $revealed = $this->source()->reveal($lead);

        $this->assertSame('ada@acmepay.test', $revealed->email);
        $this->assertSame('acmepay.test', $revealed->domain);
        $this->assertSame('Nigeria', $revealed->country);
        $this->assertSame('https://linkedin.com/in/adaobi', $revealed->linkedinUrl);
    }

    public function test_people_without_an_id_are_skipped(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 2, 'people' => [$this->person(['id' => '']), $this->person()]])]);

        $this->assertCount(1, $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25)->leads);
    }

    /**
     * @return array<string, array{int, bool, string}>
     */
    public static function failures(): array
    {
        return [
            'bad key' => [401, false, 'API key'],
            'not allowed' => [403, false, 'master'],
            'bad filters' => [422, false, 'filters'],
            'rate limited' => [429, true, 'rate limit'],
            'outage' => [503, true, 'unavailable'],
        ];
    }

    #[DataProvider('failures')]
    public function test_http_failures_become_typed_exceptions(int $status, bool $retryable, string $needle): void
    {
        Http::fake([self::SEARCH => Http::response('nope', $status)]);

        try {
            $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);
            $this->fail('Expected LeadSourceException');
        } catch (LeadSourceException $e) {
            $this->assertSame($retryable, $e->retryable);
            $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
        }
    }

    public function test_missing_key_is_a_non_retryable_error_and_sends_nothing(): void
    {
        Http::fake();
        LeadConfig::setKey('apollo', '');

        $this->assertFalse($this->source()->isConfigured());

        try {
            $this->source()->search(new LeadQuery(titles: ['CEO']), 1, 25);
            $this->fail('Expected LeadSourceException');
        } catch (LeadSourceException $e) {
            $this->assertFalse($e->retryable);
        }

        Http::assertNothingSent();
    }

    public function test_test_connection_succeeds_on_200_and_throws_on_401(): void
    {
        Http::fake([self::SEARCH => Http::sequence()
            ->push(['total_entries' => 1, 'people' => []])
            ->push('no', 401)]);

        $this->source()->testConnection();

        $this->expectException(LeadSourceException::class);
        $this->source()->testConnection();
    }

    public function test_reveal_fills_in_the_email_and_company_details(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => [
            'name' => 'Ada Obi',
            'title' => 'Chief Executive Officer',
            'email' => 'Ada@AcmePay.test ',
            'email_status' => 'verified',
            'linkedin_url' => 'https://linkedin.com/in/adaobi',
            'country' => 'Nigeria',
            'organization' => ['name' => 'Acme Pay', 'primary_domain' => 'acmepay.test'],
        ]])]);

        $lead = new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada Ob***i', title: 'CEO', company: 'Acme Pay');

        $revealed = $this->source()->reveal($lead);

        $this->assertSame('ada@acmepay.test', $revealed->email);
        $this->assertSame('verified', $revealed->emailStatus);
        $this->assertSame('Ada Obi', $revealed->name);
        $this->assertSame('acmepay.test', $revealed->domain);
        $this->assertSame('Nigeria', $revealed->country);
        $this->assertSame('https://linkedin.com/in/adaobi', $revealed->linkedinUrl);

        Http::assertSent(fn (Request $r): bool => str_contains(urldecode($r->url()), 'id=p1')
            && str_contains(urldecode($r->url()), 'reveal_personal_emails=false'));
    }

    public function test_reveal_treats_a_locked_placeholder_email_as_unavailable(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => ['email' => 'email_not_unlocked@acmepay.test']])]);

        $revealed = $this->source()->reveal(new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada'));

        $this->assertNull($revealed->email);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
    }

    public function test_reveal_with_no_person_marks_the_email_unavailable(): void
    {
        Http::fake([self::MATCH => Http::response(['person' => null])]);

        $revealed = $this->source()->reveal(new LeadData(source: 'apollo', sourceId: 'p1', name: 'Ada'));

        $this->assertNull($revealed->email);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
    }
}
