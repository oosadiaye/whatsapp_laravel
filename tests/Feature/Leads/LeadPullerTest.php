<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Leads\LeadPuller;
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
}
