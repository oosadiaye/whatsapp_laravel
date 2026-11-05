<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Services\Ai\AiClient;
use App\Services\Leads\LeadQualificationRunner;
use App\Support\LeadConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScriptedAiClient;
use Tests\TestCase;

final class LeadQualificationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const MATCH = 'https://api.apollo.io/api/v1/people/match*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null, 'services.gemini.key' => 'test-gemini-key-123', 'ai.provider' => 'gemini']);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');

        // Reveal answers with "<platform id>@acme.test" so each lead gets a distinct email.
        Http::fake([self::MATCH => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['person' => [
                'name' => 'Revealed Person',
                'email' => ($query['id'] ?? 'x').'@acme.test',
                'email_status' => 'verified',
                'organization' => ['name' => 'Acme', 'primary_domain' => 'acme.test'],
            ]]);
        }]);
    }

    private function ai(array $replies): ScriptedAiClient
    {
        $ai = new ScriptedAiClient($replies);
        $this->app->instance(AiClient::class, $ai);

        return $ai;
    }

    private function profile(array $overrides = []): LeadProfile
    {
        return LeadProfile::factory()->active()->withQuery(['titles' => ['CEO'], 'exclude_titles' => ['Intern']])->create(array_merge([
            'brief' => 'Founders and CEOs of small fintech companies in Lagos.',
            'monthly_reveal_budget' => 50,
        ], $overrides));
    }

    private function newLead(LeadProfile $profile, string $id, array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'lead_profile_id' => $profile->id,
            'source' => 'apollo',
            'source_id' => $id,
            'title' => 'CEO',
            'company' => 'Co '.$id,
        ], $overrides));
    }

    private function runFor(LeadProfile $profile): array
    {
        return app(LeadQualificationRunner::class)->run($profile->fresh());
    }

    public function test_a_good_lead_is_qualified_and_revealed_and_a_poor_one_is_rejected_unrevealed(): void
    {
        $ai = $this->ai(['{"score": 90, "reason": "Great fit."}', '{"score": 10, "reason": "Wrong industry."}']);
        $profile = $this->profile();
        $good = $this->newLead($profile, 'good');
        $poor = $this->newLead($profile, 'poor');

        $result = $this->runFor($profile);

        $this->assertSame(1, $result['qualified']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame(1, $result['revealed']);
        $this->assertSame([], $result['errors']);

        $good->refresh();
        $this->assertSame(Lead::STATUS_QUALIFIED, $good->status);
        $this->assertSame(Lead::TIER_HOT, $good->tier);
        $this->assertSame(90, $good->score);
        $this->assertSame('Great fit.', $good->qualification_reason);
        $this->assertSame('good@acme.test', $good->email);
        $this->assertNotNull($good->qualified_at);

        $poor->refresh();
        $this->assertSame(Lead::STATUS_REJECTED, $poor->status);
        $this->assertNull($poor->email);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $poor->email_status);

        Http::assertSentCount(1);
        $this->assertCount(2, $ai->calls);
        $this->assertEqualsCanonicalizing(['pulled' => 0, 'qualified' => 1, 'revealed' => 1, 'rejected' => 1], [
            'pulled' => LeadEvent::where('type', 'pulled')->count(),
            'qualified' => LeadEvent::where('type', 'qualified')->count(),
            'revealed' => LeadEvent::where('type', 'revealed')->count(),
            'rejected' => LeadEvent::where('type', 'rejected')->count(),
        ]);
        $this->assertSame('ai', LeadEvent::where('type', 'qualified')->first()->actor);
    }

    public function test_leads_rejected_by_the_free_gates_make_no_ai_call_and_do_not_use_the_daily_cap(): void
    {
        $ai = $this->ai(['{"score": 90, "reason": "ok"}']);
        $profile = $this->profile(['daily_qualify_cap' => 1]);
        $this->newLead($profile, 'intern', ['title' => 'Intern']);
        $this->newLead($profile, 'noemail', ['email_status' => Lead::EMAIL_UNAVAILABLE]);
        $real = $this->newLead($profile, 'real');

        $result = $this->runFor($profile);

        $this->assertSame(2, $result['rejected']);
        $this->assertSame(1, $result['qualified']);
        $this->assertCount(1, $ai->calls);
        $this->assertSame(Lead::STATUS_QUALIFIED, $real->fresh()->status);
        $this->assertSame('system', LeadEvent::where('type', 'rejected')->first()->actor);
    }

    public function test_the_daily_ai_cap_limits_how_many_leads_are_judged(): void
    {
        $ai = $this->ai(array_fill(0, 5, '{"score": 10, "reason": "no"}'));
        $profile = $this->profile(['daily_qualify_cap' => 2]);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->newLead($profile, $id);
        }

        $this->runFor($profile);
        $this->assertCount(2, $ai->calls);
        $this->assertSame(2, Lead::where('status', Lead::STATUS_NEW)->count());

        $this->runFor($profile);
        $this->assertCount(2, $ai->calls, 'the cap is per day, so a second run judges nothing more');
    }

    public function test_an_ai_failure_counts_an_attempt_and_the_lead_is_abandoned_after_three(): void
    {
        $ai = $this->ai([null, null, null, '{"score": 99, "reason": "late"}']);
        $profile = $this->profile();
        $lead = $this->newLead($profile, 'flaky');

        $first = $this->runFor($profile);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $lead->fresh()->qualify_attempts);
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status);

        $this->runFor($profile);
        $this->runFor($profile);
        $this->assertSame(3, $lead->fresh()->qualify_attempts);

        $this->runFor($profile);
        $this->assertCount(3, $ai->calls, 'no fourth attempt');
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status);
        $this->assertSame('qualify_failed', $lead->events()->latest('id')->first()->type);
    }

    public function test_the_kill_switch_an_inactive_profile_and_a_missing_ai_key_do_nothing(): void
    {
        $ai = $this->ai(['{"score": 90, "reason": "ok"}']);
        $profile = $this->profile();
        $this->newLead($profile, 'x');

        LeadConfig::setKillSwitch(true);
        $this->assertSame(0, $this->runFor($profile)['qualified']);
        LeadConfig::setKillSwitch(false);

        $profile->update(['is_active' => false]);
        $this->assertSame(0, $this->runFor($profile)['qualified']);
        $profile->update(['is_active' => true]);

        config(['services.gemini.key' => null]);
        $result = $this->runFor($profile);
        $this->assertSame(0, $result['qualified']);
        $this->assertStringContainsString('AI provider', $result['errors'][0]);
        $this->assertStringContainsString('AI provider', $profile->fresh()->last_qualify_error);

        $this->assertCount(0, $ai->calls);
        Http::assertNothingSent();
    }

    public function test_when_the_budget_runs_out_qualified_leads_wait_and_are_revealed_later_without_the_ai(): void
    {
        $ai = $this->ai(['{"score": 90, "reason": "a"}', '{"score": 90, "reason": "b"}']);
        $profile = $this->profile(['monthly_reveal_budget' => 1]);
        $a = $this->newLead($profile, 'a');
        $b = $this->newLead($profile, 'b');

        $first = $this->runFor($profile);

        $this->assertSame(2, $first['qualified']);
        $this->assertSame(1, $first['revealed']);
        $this->assertSame(1, $first['deferred']);
        $this->assertStringContainsString('budget', strtolower($profile->fresh()->last_qualify_error));
        $this->assertNull($b->fresh()->email);
        $this->assertSame(Lead::STATUS_QUALIFIED, $b->fresh()->status);

        $profile->update(['monthly_reveal_budget' => 5]);
        $second = $this->runFor($profile);

        $this->assertSame(1, $second['revealed']);
        $this->assertSame('b@acme.test', $b->fresh()->email);
        $this->assertCount(2, $ai->calls, 'revealing the waiting lead needs no new AI call');
        $this->assertNull($profile->fresh()->last_qualify_error);
    }

    public function test_a_platform_error_on_reveal_does_not_stop_qualification_and_is_reported(): void
    {
        // setUp's stub would win over a second fake, so start from a clean HTTP factory.
        Http::swap(new HttpFactory);
        Http::fake([self::MATCH => Http::response('no', 401)]);
        $this->ai(['{"score": 90, "reason": "a"}', '{"score": 90, "reason": "b"}']);
        $profile = $this->profile();
        $this->newLead($profile, 'a');
        $this->newLead($profile, 'b');

        $result = $this->runFor($profile);

        $this->assertSame(2, $result['qualified']);
        $this->assertSame(0, $result['revealed']);
        $this->assertSame(2, Lead::where('status', Lead::STATUS_QUALIFIED)->whereNull('email')->count());
        $this->assertStringContainsString('API key', $result['errors'][0]);
        Http::assertSentCount(1);
    }

    public function test_an_unconfigured_platform_defers_reveals_with_a_clear_error(): void
    {
        $this->ai(['{"score": 90, "reason": "a"}']);
        LeadConfig::setKey('apollo', '');
        $profile = $this->profile();
        $this->newLead($profile, 'a');

        $result = $this->runFor($profile);

        $this->assertSame(1, $result['qualified']);
        $this->assertStringContainsString('not configured', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_a_revealed_duplicate_email_is_rejected_not_a_crash(): void
    {
        $this->ai(['{"score": 90, "reason": "a"}']);
        $profile = $this->profile();
        Lead::factory()->create(['source_id' => 'older', 'email' => 'dup@acme.test']);
        $lead = $this->newLead($profile, 'dup');

        $result = $this->runFor($profile);

        $this->assertSame(1, $result['qualified']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame(Lead::STATUS_REJECTED, $lead->fresh()->status);
        $this->assertNull($lead->fresh()->email);
    }

    public function test_an_overlapping_run_is_refused(): void
    {
        $ai = $this->ai(['{"score": 90, "reason": "a"}']);
        $profile = $this->profile();
        $this->newLead($profile, 'a');
        $lock = Cache::lock("lead-qualify:{$profile->id}", 60);
        $this->assertTrue($lock->get());

        $result = $this->runFor($profile);
        $lock->release();

        $this->assertStringContainsString('already', $result['errors'][0]);
        $this->assertCount(0, $ai->calls);
    }

    public function test_a_clean_run_clears_the_previous_error_and_only_this_profiles_leads_are_touched(): void
    {
        $this->ai(['{"score": 90, "reason": "a"}']);
        $profile = $this->profile(['last_qualify_error' => 'old problem']);
        $other = $this->profile();
        $mine = $this->newLead($profile, 'mine');
        $theirs = $this->newLead($other, 'theirs');

        $this->runFor($profile);

        $this->assertNull($profile->fresh()->last_qualify_error);
        $this->assertSame(Lead::STATUS_QUALIFIED, $mine->fresh()->status);
        $this->assertSame(Lead::STATUS_NEW, $theirs->fresh()->status);
    }
}
