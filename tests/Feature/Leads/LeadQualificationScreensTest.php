<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\QualifyLeadProfileJob;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Support\LeadConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

final class LeadQualificationScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
    }

    private function as(string $role = 'manager'): static
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lagos fintech founders',
            'brief' => 'Founders and CEOs of small fintech companies based in Lagos.',
            'sources' => ['apollo'],
            'daily_pull_cap' => 50,
        ], $overrides);
    }

    public function test_a_new_profile_gets_the_default_thresholds_and_budget(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient(json_encode(['titles' => ['CEO']])));

        $this->as()->post(route('lead-profiles.store'), $this->payload());

        $profile = LeadProfile::firstOrFail();
        $this->assertSame([75, 50, 100, 200], [$profile->hot_threshold, $profile->warm_threshold, $profile->daily_qualify_cap, $profile->monthly_reveal_budget]);
    }

    public function test_the_qualification_fields_are_saved_on_create_and_update(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient(json_encode(['titles' => ['CEO']])));

        $this->as()->post(route('lead-profiles.store'), $this->payload([
            'hot_threshold' => 80, 'warm_threshold' => 60, 'daily_qualify_cap' => 30, 'monthly_reveal_budget' => 120,
        ]));
        $profile = LeadProfile::firstOrFail();
        $this->assertSame([80, 60, 30, 120], [$profile->hot_threshold, $profile->warm_threshold, $profile->daily_qualify_cap, $profile->monthly_reveal_budget]);

        $this->put(route('lead-profiles.update', $profile), $this->payload(['hot_threshold' => 90, 'monthly_reveal_budget' => 0]));
        $profile->refresh();
        $this->assertSame(90, $profile->hot_threshold);
        $this->assertSame(0, $profile->monthly_reveal_budget);
        $this->assertSame(60, $profile->warm_threshold, 'an omitted field keeps its value');
        $this->assertSame(30, $profile->daily_qualify_cap);
    }

    public function test_invalid_qualification_values_are_rejected(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient(json_encode(['titles' => ['CEO']])));

        $this->as()->post(route('lead-profiles.store'), $this->payload([
            'hot_threshold' => 40, 'warm_threshold' => 60, 'daily_qualify_cap' => 0, 'monthly_reveal_budget' => -1,
        ]))->assertSessionHasErrors(['warm_threshold', 'daily_qualify_cap', 'monthly_reveal_budget']);

        $this->post(route('lead-profiles.store'), $this->payload(['hot_threshold' => 101]))->assertSessionHasErrors('hot_threshold');
        $this->assertSame(0, LeadProfile::count());
    }

    public function test_the_edit_page_shows_credits_pipeline_counts_and_the_last_error_escaped(): void
    {
        $profile = LeadProfile::factory()->withQuery()->create([
            'monthly_reveal_budget' => 40,
            'last_qualify_error' => '<script>alert(1)</script>',
        ]);
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id, 'status' => Lead::STATUS_QUALIFIED]);
        Lead::factory()->count(2)->create(['lead_profile_id' => $profile->id, 'status' => Lead::STATUS_NEW]);
        LeadEvent::record($lead, 'revealed', ['credits' => 1]);

        $this->as()->get(route('lead-profiles.edit', $profile))
            ->assertOk()
            ->assertSee('1 of 40')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_qualify_now_queues_a_run_for_an_active_profile(): void
    {
        Queue::fake();
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        $this->as()->post(route('lead-profiles.qualify', $profile))->assertSessionHas('success');

        Queue::assertPushed(QualifyLeadProfileJob::class, fn (QualifyLeadProfileJob $job) => $job->profileId === $profile->id);
    }

    public function test_qualify_now_refuses_inactive_profiles_the_kill_switch_and_a_missing_ai_key(): void
    {
        Queue::fake();
        $inactive = LeadProfile::factory()->withQuery()->create(['is_active' => false]);
        $this->as()->post(route('lead-profiles.qualify', $inactive))->assertSessionHas('warning');

        $active = LeadProfile::factory()->active()->withQuery()->create();
        LeadConfig::setKillSwitch(true);
        $this->post(route('lead-profiles.qualify', $active))->assertSessionHas('warning');
        LeadConfig::setKillSwitch(false);

        config(['services.gemini.key' => null]);
        $this->post(route('lead-profiles.qualify', $active))->assertSessionHas('warning');

        Queue::assertNothingPushed();
    }

    public function test_agents_cannot_queue_a_qualification_run(): void
    {
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        $this->as('agent')->post(route('lead-profiles.qualify', $profile))->assertForbidden();
    }

    public function test_the_leads_list_shows_score_tier_and_links_to_the_detail_page(): void
    {
        $lead = Lead::factory()->create([
            'name' => 'Ada Obi', 'score' => 88, 'tier' => Lead::TIER_HOT, 'qualification_reason' => 'Strong fit.',
        ]);

        $this->as()->get(route('leads.index'))
            ->assertOk()
            ->assertSee('88')
            ->assertSee('Hot')
            ->assertSee(route('leads.show', $lead), false);
    }

    public function test_the_lead_detail_page_shows_the_reason_and_the_timeline_newest_first_escaped(): void
    {
        $profile = LeadProfile::factory()->create(['name' => 'Fintech']);
        $lead = Lead::factory()->create([
            'lead_profile_id' => $profile->id,
            'name' => '<b>Ada</b>',
            'email' => 'ada@acme.test',
            'score' => 82,
            'tier' => Lead::TIER_HOT,
            'qualification_reason' => 'Strong fit for the brief.',
        ]);
        LeadEvent::record($lead, 'pulled', ['profile_id' => $profile->id]);
        LeadEvent::record($lead, 'qualified', ['score' => 82, 'tier' => 'hot'], 'ai');
        LeadEvent::record($lead, 'revealed', ['credits' => 1]);

        $this->as()->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('ada@acme.test')
            ->assertSee('Strong fit for the brief.')
            ->assertSee('Fintech')
            ->assertSee('&lt;b&gt;Ada&lt;/b&gt;', false)
            ->assertDontSee('<b>Ada</b>', false)
            ->assertSeeInOrder(['Email revealed', 'Qualified', 'Pulled']);
    }

    public function test_the_lead_detail_page_requires_leads_view_and_404s_for_unknown_leads(): void
    {
        $lead = Lead::factory()->create();

        $this->as('agent')->get(route('leads.show', $lead))->assertForbidden();
        $this->as('manager')->get(route('leads.show', 999999))->assertNotFound();
    }
}
