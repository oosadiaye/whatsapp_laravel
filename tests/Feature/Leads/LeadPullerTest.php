<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadPage;
use App\Services\Leads\LeadPuller;
use App\Services\Leads\LeadQuery;
use App\Services\Leads\LeadSource;
use App\Services\Leads\LeadSourceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LeadPullerTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
    }

    private function profile(array $overrides = []): LeadProfile
    {
        return LeadProfile::factory()->active()->withQuery()->create($overrides);
    }

    private function people(int $count, int $offset = 0): array
    {
        $people = [];
        for ($i = 1; $i <= $count; $i++) {
            $people[] = ['id' => 'p'.($offset + $i), 'first_name' => 'Person', 'last_name_obfuscated' => 'A***'.$i, 'title' => 'CEO', 'has_email' => true, 'organization' => ['name' => 'Co '.$i]];
        }

        return $people;
    }

    public function test_a_pull_stores_leads_and_advances_the_cursor(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(3)])]);
        $profile = $this->profile();

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(3, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, Lead::where('lead_profile_id', $profile->id)->count());

        $profile->refresh();
        $this->assertSame(['next_page' => 2, 'exhausted' => false], $profile->sourceState('apollo'));
        $this->assertNotNull($profile->last_pulled_at);
        $this->assertNull($profile->last_pull_error);
    }

    public function test_the_next_pull_asks_for_the_next_page(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(1)])]);
        $profile = $this->profile(['pull_state' => ['apollo' => ['next_page' => 4, 'exhausted' => false]]]);

        app(LeadPuller::class)->pull($profile);

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'page=4'));
    }

    public function test_a_source_is_marked_exhausted_when_there_are_no_more_pages(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 2, 'people' => $this->people(2)])]);
        $profile = $this->profile();
        $puller = app(LeadPuller::class);

        $puller->pull($profile);
        $this->assertTrue($profile->fresh()->sourceState('apollo')['exhausted']);

        Http::fake();
        $puller->pull($profile->fresh());
        Http::assertNothingSent();
    }

    public function test_no_request_is_made_once_todays_cap_is_reached(): void
    {
        Http::fake();
        $profile = $this->profile(['daily_pull_cap' => 25]);
        Lead::factory()->count(25)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo']);

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
    }

    public function test_leads_pulled_yesterday_do_not_count_against_today(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(2, 100)])]);
        $profile = $this->profile(['daily_pull_cap' => 25]);
        Lead::factory()->count(25)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo', 'created_at' => now()->subDay()]);

        $this->assertSame(2, app(LeadPuller::class)->pull($profile)['created']);
    }

    public function test_the_kill_switch_stops_everything(): void
    {
        Http::fake();
        LeadConfig::setKillSwitch(true);

        $result = app(LeadPuller::class)->pull($this->profile());

        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
    }

    public function test_inactive_profiles_and_profiles_without_a_query_are_skipped(): void
    {
        Http::fake();

        app(LeadPuller::class)->pull(LeadProfile::factory()->withQuery()->create(['is_active' => false]));
        app(LeadPuller::class)->pull(LeadProfile::factory()->active()->create(['lead_query' => null]));

        Http::assertNothingSent();
    }

    public function test_a_platform_error_is_recorded_and_the_cursor_does_not_move(): void
    {
        Http::fake([self::SEARCH => Http::response('no', 401)]);
        $profile = $this->profile(['pull_state' => ['apollo' => ['next_page' => 2, 'exhausted' => false]]]);

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);

        $profile->refresh();
        $this->assertStringContainsString('API key', $profile->last_pull_error);
        $this->assertSame(2, $profile->sourceState('apollo')['next_page']);
    }

    public function test_an_unconfigured_source_is_reported_not_called(): void
    {
        Http::fake();
        LeadConfig::setKey('apollo', '');

        $result = app(LeadPuller::class)->pull($this->profile());

        $this->assertStringContainsString('not configured', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_an_unknown_source_key_is_reported(): void
    {
        $result = app(LeadPuller::class)->pull($this->profile(['sources' => ['nope']]));

        $this->assertStringContainsString('nope', $result['errors'][0]);
    }

    public function test_a_successful_pull_clears_an_old_error(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 1, 'people' => $this->people(1)])]);
        $profile = $this->profile(['last_pull_error' => 'old failure']);

        app(LeadPuller::class)->pull($profile);

        $this->assertNull($profile->fresh()->last_pull_error);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_pull_is_refused_while_another_is_running_for_the_same_profile(): void
    {
        Http::fake();
        $profile = $this->profile();
        $before = $profile->fresh()->getAttributes();
        $lock = Cache::lock("lead-pull:{$profile->id}", 120);
        $this->assertTrue($lock->get());

        try {
            $result = app(LeadPuller::class)->pull($profile);
        } finally {
            $lock->release();
        }

        $this->assertSame(['A pull for this profile is already running.'], $result['errors']);
        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public function test_the_lock_is_released_after_a_pull(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(1)])]);
        $profile = $this->profile();

        app(LeadPuller::class)->pull($profile);

        $lock = Cache::lock("lead-pull:{$profile->id}", 120);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_the_day_boundary_follows_the_business_timezone(): void
    {
        config(['app.business_timezone' => 'Africa/Lagos']);
        // 00:30 on 5 Nov in Lagos is 23:30 on 4 Nov UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-04 23:30:00', 'UTC'));
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(2, 500)])]);

        // 23:30 Lagos time on 4 Nov = 22:30 UTC: yesterday in Lagos, so it must not count.
        $profile = $this->profile(['daily_pull_cap' => 2]);
        Lead::factory()->count(2)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo', 'created_at' => Carbon::parse('2026-11-04 22:30:00', 'UTC')]);
        $this->assertSame(2, app(LeadPuller::class)->pull($profile)['created']);

        // 00:15 Lagos time on 5 Nov = 23:15 UTC: today in Lagos, so it counts.
        $other = $this->profile(['daily_pull_cap' => 2]);
        Lead::factory()->count(2)->create(['lead_profile_id' => $other->id, 'source' => 'apollo', 'created_at' => Carbon::parse('2026-11-04 23:15:00', 'UTC')]);
        Http::fake();
        $this->assertSame(0, app(LeadPuller::class)->pull($other)['created']);
        Http::assertNothingSent();
    }

    public function test_one_below_the_cap_still_pulls(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(2, 900)])]);
        $profile = $this->profile(['daily_pull_cap' => 25]);
        Lead::factory()->count(24)->create(['lead_profile_id' => $profile->id, 'source' => 'apollo']);

        $this->assertSame(2, app(LeadPuller::class)->pull($profile)['created']);
    }

    public function test_an_empty_page_marks_the_source_exhausted(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => []])]);
        $profile = $this->profile();

        app(LeadPuller::class)->pull($profile);

        $this->assertTrue($profile->fresh()->sourceState('apollo')['exhausted']);
    }

    public function test_failed_stores_are_reported_but_the_cursor_still_advances(): void
    {
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(3)])]);
        Lead::creating(function (Lead $lead): void {
            if ($lead->source_id === 'p2') {
                throw new \RuntimeException('boom');
            }
        });
        $profile = $this->profile();

        try {
            $result = app(LeadPuller::class)->pull($profile);
        } finally {
            Lead::flushEventListeners();
        }

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('1 lead(s) from Apollo', $result['errors'][0]);
        $this->assertSame(2, $profile->fresh()->sourceState('apollo')['next_page']);
    }

    public function test_one_failing_source_does_not_disturb_the_other_sources_state(): void
    {
        config(['leads.sources' => [
            'broken' => BrokenTestSource::class,
            'apollo' => \App\Services\Leads\Apollo\ApolloLeadSource::class,
        ]]);
        Http::fake([self::SEARCH => Http::response(['total_entries' => 100, 'people' => $this->people(2)])]);
        $profile = $this->profile([
            'sources' => ['broken', 'apollo'],
            'pull_state' => ['broken' => ['next_page' => 3, 'exhausted' => false]],
        ]);

        $result = app(LeadPuller::class)->pull($profile);

        $this->assertSame(2, $result['created']);
        $this->assertCount(1, $result['errors']);
        $profile->refresh();
        $this->assertSame(['next_page' => 3, 'exhausted' => false], $profile->sourceState('broken'));
        $this->assertSame(['next_page' => 2, 'exhausted' => false], $profile->sourceState('apollo'));
    }
}

/** Test double: configured, but every search fails. */
final class BrokenTestSource implements LeadSource
{
    public function key(): string
    {
        return 'broken';
    }

    public function label(): string
    {
        return 'Broken';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): void {}

    public function search(LeadQuery $query, int $page, int $perPage): LeadPage
    {
        throw new LeadSourceException('Broken source is down.', true);
    }

    public function reveal(LeadData $lead): LeadData
    {
        return $lead;
    }
}
