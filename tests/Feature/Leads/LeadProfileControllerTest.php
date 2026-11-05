<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

final class LeadProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.apollo.io/api/v1/mixed_people/api_search*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.apollo.key' => null]);
    }

    private function as(string $role = 'manager'): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    private function ai(?string $reply): FakeAiClient
    {
        $fake = new FakeAiClient($reply);
        $this->app->instance(AiClient::class, $fake);

        return $fake;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lagos fintech founders',
            'brief' => 'Founders and CEOs of small fintech companies based in Lagos.',
            'sources' => ['apollo'],
            'daily_pull_cap' => 50,
            'hf_countries' => 'Nigeria',
        ], $overrides);
    }

    public function test_agents_are_forbidden_and_managers_can_list(): void
    {
        $this->as('agent')->get(route('lead-profiles.index'))->assertForbidden();
        $this->as('manager')->get(route('lead-profiles.index'))->assertOk();
    }

    public function test_creating_a_profile_builds_and_saves_the_search_but_stays_inactive(): void
    {
        $this->ai(json_encode(['titles' => ['Founder', 'CEO'], 'keywords' => 'fintech']));

        $this->as()->post(route('lead-profiles.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = LeadProfile::firstOrFail();
        $this->assertFalse($profile->is_active);
        $this->assertSame(['Founder', 'CEO'], $profile->lead_query['titles']);
        $this->assertSame(['Nigeria'], $profile->lead_query['person_locations']);
        $this->assertSame(['Nigeria'], $profile->hard_filters['countries']);
        $this->assertSame(['apollo'], $profile->sources);
        $this->assertNotNull($profile->created_by);
    }

    public function test_if_the_ai_fails_the_profile_is_still_saved_with_a_warning(): void
    {
        $this->ai(null);

        $this->as()->post(route('lead-profiles.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('warning');

        $profile = LeadProfile::firstOrFail();
        $this->assertNull($profile->lead_query);
        $this->assertFalse($profile->hasQuery());
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->ai('{}');

        $this->as()->post(route('lead-profiles.store'), $this->payload(['name' => '', 'brief' => 'short', 'sources' => ['nope'], 'daily_pull_cap' => 0, 'hf_employee_ranges' => 'abc']))
            ->assertSessionHasErrors(['name', 'brief', 'sources.0', 'daily_pull_cap', 'hf_employee_ranges']);

        $this->assertSame(0, LeadProfile::count());
    }

    public function test_hard_filters_are_parsed_from_the_form(): void
    {
        $this->ai(json_encode(['titles' => ['CEO']]));

        $this->as()->post(route('lead-profiles.store'), $this->payload([
            'hf_countries' => 'Nigeria, Ghana',
            'hf_titles_exclude' => "Intern\nStudent",
            'hf_employee_ranges' => '11, 50; 51,200',
            'hf_require_verified_email' => '1',
        ]));

        $filters = LeadProfile::firstOrFail()->hard_filters;
        $this->assertSame(['Nigeria', 'Ghana'], $filters['countries']);
        $this->assertSame(['Intern', 'Student'], $filters['titles_exclude']);
        $this->assertSame(['11,50', '51,200'], $filters['employee_ranges']);
        $this->assertTrue($filters['require_verified_email']);
    }

    public function test_changing_the_brief_rebuilds_the_search_and_restarts_the_cursor(): void
    {
        $fake = $this->ai(json_encode(['titles' => ['CTO']]));
        $profile = LeadProfile::factory()->active()->withQuery()->create([
            'pull_state' => ['apollo' => ['next_page' => 9, 'exhausted' => true]],
            'hard_filters' => ['countries' => ['Nigeria']],
        ]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload([
            'brief' => 'A completely different brief about CTOs at banks.',
            'is_active' => '1',
        ]))->assertRedirect();

        $profile->refresh();
        $this->assertCount(1, $fake->calls);
        $this->assertSame(['CTO'], $profile->lead_query['titles']);
        $this->assertNull($profile->pull_state);
        $this->assertTrue($profile->is_active);
    }

    public function test_saving_without_changing_the_brief_does_not_call_the_ai(): void
    {
        $fake = $this->ai(json_encode(['titles' => ['CTO']]));
        $profile = LeadProfile::factory()->withQuery(['titles' => ['CEO']])->create([
            'brief' => 'Founders and CEOs of small fintech companies based in Lagos.',
            'hard_filters' => ['countries' => ['Nigeria'], 'titles_include' => [], 'titles_exclude' => [], 'employee_ranges' => [], 'require_verified_email' => false],
        ]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload(['name' => 'Renamed']))->assertRedirect();

        $this->assertCount(0, $fake->calls);
        $this->assertSame('Renamed', $profile->fresh()->name);
        $this->assertSame(['CEO'], $profile->fresh()->lead_query['titles']);
    }

    public function test_a_profile_without_a_generated_search_cannot_be_activated(): void
    {
        $this->ai(null);
        $profile = LeadProfile::factory()->create(['lead_query' => null]);

        $this->as()->put(route('lead-profiles.update', $profile), $this->payload(['is_active' => '1']))
            ->assertSessionHas('warning');

        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_rebuild_query_action(): void
    {
        $this->ai(json_encode(['titles' => ['Head of Sales']]));
        $profile = LeadProfile::factory()->create(['lead_query' => null]);

        $this->as()->post(route('lead-profiles.rebuild-query', $profile))->assertSessionHas('success');

        $this->assertSame(['Head of Sales'], $profile->fresh()->lead_query['titles']);
    }

    public function test_pull_now_stores_leads_and_reports_the_count(): void
    {
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake([self::SEARCH => Http::response([
            'total_entries' => 1,
            'people' => [['id' => 'p1', 'first_name' => 'Ada', 'last_name_obfuscated' => 'O**', 'has_email' => true, 'organization' => ['name' => 'Co']]],
        ])]);
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        $this->as()->post(route('lead-profiles.pull', $profile))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 new lead'));

        $this->assertSame(1, Lead::count());
    }

    public function test_pull_now_refuses_an_inactive_profile_and_the_kill_switch(): void
    {
        Http::fake();

        $this->as()->post(route('lead-profiles.pull', LeadProfile::factory()->withQuery()->create(['is_active' => false])))
            ->assertSessionHas('warning');

        LeadConfig::setKillSwitch(true);
        $this->post(route('lead-profiles.pull', LeadProfile::factory()->active()->withQuery()->create()))
            ->assertSessionHas('warning');

        Http::assertNothingSent();
    }

    public function test_reset_pull_clears_the_cursor_and_error(): void
    {
        $profile = LeadProfile::factory()->create([
            'pull_state' => ['apollo' => ['next_page' => 5, 'exhausted' => true]],
            'last_pull_error' => 'boom',
        ]);

        $this->as()->post(route('lead-profiles.reset-pull', $profile))->assertRedirect();

        $profile->refresh();
        $this->assertNull($profile->pull_state);
        $this->assertNull($profile->last_pull_error);
    }

    public function test_deleting_a_profile_keeps_its_leads(): void
    {
        $profile = LeadProfile::factory()->create();
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id]);

        $this->as()->delete(route('lead-profiles.destroy', $profile))->assertRedirect(route('lead-profiles.index'));

        $this->assertNull(LeadProfile::find($profile->id));
        $this->assertNotNull($lead->fresh());
    }

    public function test_the_edit_page_shows_the_generated_search_for_review(): void
    {
        $profile = LeadProfile::factory()->withQuery(['titles' => ['Chief Technology Officer'], 'keywords' => 'logistics'])->create();

        $this->as()->get(route('lead-profiles.edit', $profile))
            ->assertOk()
            ->assertSee('Chief Technology Officer')
            ->assertSee('logistics');
    }
}
