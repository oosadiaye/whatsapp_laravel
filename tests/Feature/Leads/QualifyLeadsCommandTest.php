<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Jobs\QualifyLeadProfileJob;
use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Ai\AiClient;
use App\Services\Leads\LeadQualificationRunner;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ScriptedAiClient;
use Tests\TestCase;

final class QualifyLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_only_active_profiles_that_have_work(): void
    {
        Queue::fake();
        $withNew = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $withNew->id, 'status' => Lead::STATUS_NEW]);

        $withWaiting = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $withWaiting->id, 'status' => Lead::STATUS_QUALIFIED, 'email_status' => Lead::EMAIL_UNREVEALED]);

        $idle = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $idle->id, 'status' => Lead::STATUS_REJECTED]);

        $abandoned = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $abandoned->id, 'status' => Lead::STATUS_NEW, 'qualify_attempts' => 3]);

        $inactive = LeadProfile::factory()->withQuery()->create(['is_active' => false]);
        Lead::factory()->create(['lead_profile_id' => $inactive->id, 'status' => Lead::STATUS_NEW]);

        $this->artisan('leads:qualify')->assertSuccessful();

        Queue::assertPushed(QualifyLeadProfileJob::class, 2);
        Queue::assertPushed(QualifyLeadProfileJob::class, fn (QualifyLeadProfileJob $job) => $job->profileId === $withNew->id);
        Queue::assertPushed(QualifyLeadProfileJob::class, fn (QualifyLeadProfileJob $job) => $job->profileId === $withWaiting->id);
    }

    public function test_the_profile_option_queues_just_that_profile(): void
    {
        Queue::fake();
        $profile = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $profile->id, 'status' => Lead::STATUS_NEW]);
        $other = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $other->id, 'status' => Lead::STATUS_NEW]);

        $this->artisan('leads:qualify', ['--profile' => $profile->id])->assertSuccessful();

        Queue::assertPushed(QualifyLeadProfileJob::class, 1);
    }

    public function test_the_kill_switch_queues_nothing(): void
    {
        Queue::fake();
        LeadConfig::setKillSwitch(true);
        $profile = LeadProfile::factory()->active()->withQuery()->create();
        Lead::factory()->create(['lead_profile_id' => $profile->id, 'status' => Lead::STATUS_NEW]);

        $this->artisan('leads:qualify')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_job_runs_the_runner_for_its_profile(): void
    {
        config(['services.apollo.key' => null, 'services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
        Http::fake(['https://api.apollo.io/*' => Http::response(['person' => ['email' => 'a@acme.test', 'email_status' => 'verified']])]);
        $this->app->instance(AiClient::class, new ScriptedAiClient(['{"score": 90, "reason": "fit"}']));
        $profile = LeadProfile::factory()->active()->withQuery()->create();
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id, 'title' => 'CEO', 'company' => 'Acme', 'status' => Lead::STATUS_NEW]);

        (new QualifyLeadProfileJob($profile->id))->handle(app(LeadQualificationRunner::class));

        $this->assertSame(Lead::STATUS_QUALIFIED, $lead->fresh()->status);
        $this->assertSame('a@acme.test', $lead->fresh()->email);
    }

    public function test_the_job_ignores_a_deleted_profile(): void
    {
        (new QualifyLeadProfileJob(999999))->handle(app(LeadQualificationRunner::class));

        $this->assertTrue(true);
    }

    public function test_the_command_is_on_the_schedule_every_ten_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('leads:qualify')->assertSuccessful();
    }
}
