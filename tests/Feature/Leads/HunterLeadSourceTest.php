<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Services\Leads\Hunter\HunterLeadSource;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HunterLeadSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hunter.key' => null]);
        LeadConfig::setKey('hunter', 'hunter-test-key-123');
    }

    private function source(): HunterLeadSource
    {
        return new HunterLeadSource;
    }

    public function test_search_sends_a_plain_english_query_and_returns_one_lead_per_company(): void
    {
        Http::fake(['api.hunter.io/v2/discover' => Http::response([
            'data' => [
                ['domain' => 'paystackx.com', 'organization' => 'PaystackX', 'emails_count' => ['personal' => 4, 'generic' => 1, 'total' => 5]],
                ['domain' => 'nomail.io', 'organization' => 'NoMail', 'emails_count' => ['personal' => 0, 'generic' => 0, 'total' => 0]],
                ['organization' => 'No domain'],
            ],
            'meta' => ['results' => 240, 'limit' => 25, 'offset' => 0],
        ])]);

        $page = $this->source()->search(new LeadQuery(keywords: 'fintech', organizationLocations: ['Lagos, Nigeria']), 1, 25);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->hasHeader('X-API-KEY', 'hunter-test-key-123')
            && $r['query'] === 'fintech companies in Lagos, Nigeria'
            && $r['limit'] === 25
            && ! isset($r['offset']));
        $this->assertCount(2, $page->leads, 'a company without a domain is dropped');
        $this->assertSame('hunter', $page->leads[0]->source);
        $this->assertSame('paystackx.com', $page->leads[0]->sourceId);
        $this->assertSame('PaystackX', $page->leads[0]->company);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $page->leads[0]->emailStatus);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $page->leads[1]->emailStatus, 'Hunter knows no people there');
        $this->assertSame(240, $page->total);
        $this->assertFalse($page->hasMore, 'fewer than a full page came back');
    }

    public function test_later_pages_use_an_offset(): void
    {
        Http::fake(['api.hunter.io/v2/discover' => Http::response(['data' => [], 'meta' => ['results' => 0]])]);

        $this->source()->search(new LeadQuery(keywords: 'fintech'), 3, 25);

        Http::assertSent(fn (Request $r) => $r['offset'] === 50);
    }

    public function test_reveal_takes_the_most_senior_person_with_a_usable_email(): void
    {
        Http::fake(['api.hunter.io/v2/domain-search*' => Http::response(['data' => ['emails' => [
            ['value' => 'intern@paystackx.com', 'first_name' => 'In', 'last_name' => 'Tern', 'position' => 'Intern', 'seniority' => 'junior', 'confidence' => 99, 'verification' => ['status' => 'valid']],
            ['value' => 'ceo@paystackx.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'CEO', 'seniority' => 'executive', 'confidence' => 90, 'decision_maker' => true, 'linkedin' => 'https://linkedin.com/in/ada', 'verification' => ['status' => 'valid']],
            ['value' => 'bad@paystackx.com', 'first_name' => 'Bad', 'seniority' => 'executive', 'confidence' => 100, 'verification' => ['status' => 'invalid']],
        ]]])]);
        $lead = new LeadData('hunter', 'paystackx.com', 'PaystackX', company: 'PaystackX', domain: 'paystackx.com');

        $revealed = $this->source()->reveal($lead);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['domain'] === 'paystackx.com' && $r['seniority'] === 'senior,executive');
        $this->assertSame('ceo@paystackx.com', $revealed->email);
        $this->assertSame('Ada Obi', $revealed->name);
        $this->assertSame('CEO', $revealed->title);
        $this->assertSame('valid', $revealed->emailStatus);
        $this->assertSame('https://linkedin.com/in/ada', $revealed->linkedinUrl);
    }

    public function test_reveal_with_nobody_usable_marks_the_lead_unavailable(): void
    {
        Http::fake(['api.hunter.io/v2/domain-search*' => Http::response(['data' => ['emails' => []]])]);

        $revealed = $this->source()->reveal(new LeadData('hunter', 'x.com', 'X', domain: 'x.com'));

        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
        $this->assertNull($revealed->email);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function failures(): array
    {
        return ['bad key' => [401, false], 'rate limit' => [403, true], 'usage limit' => [429, false], 'outage' => [503, true], 'bad request' => [422, false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_failures_say_whether_trying_again_can_help(int $status, bool $retryable): void
    {
        Http::fake(['api.hunter.io/*' => Http::response(['errors' => []], $status)]);

        try {
            $this->source()->search(new LeadQuery(keywords: 'fintech'), 1, 25);
            $this->fail('expected an exception');
        } catch (LeadSourceException $e) {
            $this->assertSame($retryable, $e->retryable);
        }
    }

    public function test_a_missing_key_is_a_clear_error_and_the_test_uses_the_free_account_call(): void
    {
        Http::fake(['api.hunter.io/v2/account' => Http::response(['data' => []])]);
        $this->source()->testConnection();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/account'));

        LeadConfig::setKey('hunter', '');
        $this->expectException(LeadSourceException::class);
        $this->source()->testConnection();
    }
}
