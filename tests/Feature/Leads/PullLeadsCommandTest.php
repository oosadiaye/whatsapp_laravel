<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\PullLeadProfileJob;
use App\Models\LeadProfile;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PullLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_only_active_profiles_with_a_query_that_are_due(): void
    {
        Queue::fake();
        $due = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subHours(2)]);
        $never = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => null]);
        LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subMinutes(5)]);
        LeadProfile::factory()->withQuery()->create(['is_active' => false]);
        LeadProfile::factory()->active()->create(['lead_query' => null]);

        $this->artisan('leads:pull')->assertSuccessful();

        Queue::assertPushed(PullLeadProfileJob::class, 2);
        Queue::assertPushed(PullLeadProfileJob::class, fn (PullLeadProfileJob $job) => $job->profileId === $due->id);
        Queue::assertPushed(PullLeadProfileJob::class, fn (PullLeadProfileJob $job) => $job->profileId === $never->id);
    }

    public function test_the_profile_option_forces_one_profile_even_if_recently_pulled(): void
    {
        Queue::fake();
        $recent = LeadProfile::factory()->active()->withQuery()->create(['last_pulled_at' => now()->subMinutes(1)]);

        $this->artisan('leads:pull', ['--profile' => $recent->id])->assertSuccessful();

        Queue::assertPushed(PullLeadProfileJob::class, 1);
    }

    public function test_the_kill_switch_queues_nothing(): void
    {
        Queue::fake();
        LeadConfig::setKillSwitch(true);
        LeadProfile::factory()->active()->withQuery()->create();

        $this->artisan('leads:pull')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_job_runs_the_puller_for_its_profile(): void
    {
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake(['https://api.apollo.io/*' => Http::response([
            'total_entries' => 1,
            'people' => [['id' => 'p1', 'first_name' => 'A', 'last_name_obfuscated' => 'B**', 'has_email' => true, 'organization' => ['name' => 'Co']]],
        ])]);
        $profile = LeadProfile::factory()->active()->withQuery()->create();

        (new PullLeadProfileJob($profile->id))->handle(app(\App\Services\Leads\LeadPuller::class));

        $this->assertSame(1, $profile->leads()->count());
    }

    public function test_the_job_ignores_a_profile_that_was_deleted(): void
    {
        Bus::fake();

        (new PullLeadProfileJob(999999))->handle(app(\App\Services\Leads\LeadPuller::class));

        $this->assertTrue(true);
    }

    public function test_the_pull_is_on_the_schedule(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('leads:pull')->assertSuccessful();
    }
}
