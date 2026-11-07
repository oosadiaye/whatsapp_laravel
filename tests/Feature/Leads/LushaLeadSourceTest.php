<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSourceException;
use App\Services\Leads\Lusha\LushaLeadSource;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LushaLeadSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lusha.key' => null]);
        LeadConfig::setKey('lusha', 'lusha-test-key-123');
    }

    public function test_search_builds_the_v3_filters_and_normalises_the_people(): void
    {
        Http::fake(['api.lusha.com/v3/contacts/prospecting' => Http::response([
            'results' => [
                ['id' => '4389', 'firstName' => 'Ada', 'lastName' => 'Obi', 'jobTitle' => ['title' => 'CEO'], 'company' => ['name' => 'Acme', 'domain' => 'ACME.com'],
                    'location' => ['country' => 'Nigeria'], 'socialLinks' => ['linkedin' => 'https://linkedin.com/in/ada'], 'has' => ['emails']],
                ['id' => '4390', 'firstName' => 'Bo', 'jobTitle' => ['title' => 'Founder'], 'company' => ['name' => 'Beta'], 'has' => [], 'canReveal' => []],
                ['firstName' => 'No id'],
            ],
            'pagination' => ['page' => 0, 'size' => 25, 'total' => 80],
            'billing' => ['creditsCharged' => 0],
        ])]);
        $query = new LeadQuery(titles: ['CEO', 'Founder'], personLocations: ['Lagos, Nigeria', 'Ghana'], employeeRanges: ['10,50'], keywords: 'fintech');

        $page = (new LushaLeadSource)->search($query, 1, 25);

        Http::assertSent(function (Request $r) {
            $body = json_decode(json_encode($r->data()), true);

            return $r->hasHeader('api_key', 'lusha-test-key-123')
                && $body['pagination'] === ['page' => 0, 'size' => 25]
                && $body['filters']['contacts']['include']['jobTitles'] === ['CEO', 'Founder']
                && $body['filters']['contacts']['include']['locations'] === [['country' => 'Nigeria', 'city' => 'Lagos'], ['country' => 'Ghana']]
                && $body['filters']['companies']['include']['sizes'] === [['min' => 10, 'max' => 50]];
        });
        $this->assertCount(2, $page->leads);
        $first = $page->leads[0];
        $this->assertSame('lusha', $first->source);
        $this->assertSame('4389', $first->sourceId);
        $this->assertSame('Ada Obi', $first->name);
        $this->assertSame('CEO', $first->title);
        $this->assertSame('Acme', $first->company);
        $this->assertSame('acme.com', $first->domain);
        $this->assertSame('Nigeria', $first->country);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $first->emailStatus);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $page->leads[1]->emailStatus);
        $this->assertSame(80, $page->total);
        $this->assertTrue($page->hasMore);
    }

    public function test_an_empty_filter_group_is_sent_as_an_object_not_a_list(): void
    {
        Http::fake(['api.lusha.com/*' => Http::response(['results' => [], 'pagination' => ['total' => 0]])]);

        (new LushaLeadSource)->search(new LeadQuery(titles: ['CEO']), 2, 5);

        Http::assertSent(function (Request $r) {
            $raw = json_encode($r->data());

            return str_contains($raw, '"exclude":{}') && str_contains($raw, '"page":1') && str_contains($raw, '"size":10');
        });
    }

    public function test_reveal_asks_for_the_email_only_and_prefers_a_work_address(): void
    {
        Http::fake(['api.lusha.com/v3/contacts/enrich' => Http::response(['results' => [
            ['id' => '4389', 'emails' => [
                ['email' => 'Ada.Private@gmail.com', 'type' => 'private', 'confidence' => 'high'],
                ['email' => 'Ada@Acme.com', 'type' => 'work', 'confidence' => 'medium'],
            ]],
        ], 'billing' => ['creditsCharged' => 1]])]);

        $revealed = (new LushaLeadSource)->reveal(new LeadData('lusha', '4389', 'Ada Obi'));

        Http::assertSent(fn (Request $r) => $r->data() === ['ids' => ['4389'], 'reveal' => ['emails']]);
        $this->assertSame('ada@acme.com', $revealed->email);
        $this->assertSame('medium', $revealed->emailStatus);
    }

    public function test_reveal_without_an_email_marks_the_lead_unavailable(): void
    {
        Http::fake(['api.lusha.com/v3/contacts/enrich' => Http::response(['results' => [['id' => '4389', 'error' => 'NOT_FOUND']]])]);

        $revealed = (new LushaLeadSource)->reveal(new LeadData('lusha', '4389', 'Ada'));

        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $revealed->emailStatus);
    }

    public function test_a_pending_async_reveal_is_retried_later_not_treated_as_empty(): void
    {
        Http::fake(['api.lusha.com/v3/contacts/enrich' => Http::response(['job' => ['id' => 'j1', 'retryAfter' => 5]])]);

        try {
            (new LushaLeadSource)->reveal(new LeadData('lusha', '4389', 'Ada'));
            $this->fail('expected an exception');
        } catch (LeadSourceException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function failures(): array
    {
        return ['bad key' => [401, false], 'no credits' => [402, false], 'inactive' => [403, false], 'bad request' => [400, false], 'rate limit' => [429, true], 'outage' => [502, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_failures_say_whether_trying_again_can_help(int $status, bool $retryable): void
    {
        Http::fake(['api.lusha.com/*' => Http::response(['message' => 'x'], $status)]);

        try {
            (new LushaLeadSource)->search(new LeadQuery(titles: ['CEO']), 1, 25);
            $this->fail('expected an exception');
        } catch (LeadSourceException $e) {
            $this->assertSame($retryable, $e->retryable);
        }
    }

    public function test_the_connection_test_lists_a_filter_for_free(): void
    {
        Http::fake(['api.lusha.com/v3/contacts/prospecting/filters/seniority' => Http::response([])]);

        (new LushaLeadSource)->testConnection();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    }
}
