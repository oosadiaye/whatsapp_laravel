<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResult;
use App\Services\Leads\LeadQualificationRunner;
use App\Support\LeadConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    protected function tearDown(): void
    {
        Lead::flushEventListeners();
        LeadEvent::flushEventListeners();
        Carbon::setTestNow();
        parent::tearDown();
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
        // Attempts only count in a run where the provider answered at least one lead.
        $ok = '{"score": 10, "reason": "no"}';
        $ai = $this->ai([null, $ok, null, $ok, null, $ok, $ok]);
        $profile = $this->profile();
        $lead = $this->newLead($profile, 'flaky');
        $this->newLead($profile, 'g1');

        $first = $this->runFor($profile);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $lead->fresh()->qualify_attempts);
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status);

        $this->newLead($profile, 'g2');
        $this->runFor($profile);
        $this->newLead($profile, 'g3');
        $this->runFor($profile);
        $this->assertSame(3, $lead->fresh()->qualify_attempts);
        $this->assertSame('qualify_failed', $lead->events()->latest('id')->first()->type);

        $this->newLead($profile, 'g4');
        $this->runFor($profile);
        $this->assertCount(7, $ai->calls, 'flaky gets no fourth attempt, only g4 is judged');
        $this->assertSame(3, $lead->fresh()->qualify_attempts);
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status);
    }

    public function test_an_ai_outage_stops_after_three_consecutive_failures_and_burns_no_attempts(): void
    {
        $ai = $this->ai([]);
        $profile = $this->profile();
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $this->newLead($profile, $id);
        }

        $result = $this->runFor($profile);

        $this->assertCount(3, $ai->calls);
        $this->assertSame(0, (int) Lead::sum('qualify_attempts'));
        $this->assertSame(0, LeadEvent::where('type', 'qualify_failed')->count());
        $this->assertStringContainsString('not answering', $result['errors'][0]);
        $this->assertStringContainsString('not answering', $profile->fresh()->last_qualify_error);
    }

    public function test_when_the_provider_answered_at_least_once_the_failed_leads_count_an_attempt(): void
    {
        $this->ai([null, '{"score": 10, "reason": "no"}']);
        $profile = $this->profile();
        $failed = $this->newLead($profile, 'a');
        $this->newLead($profile, 'b');

        $this->runFor($profile);

        $this->assertSame(1, $failed->fresh()->qualify_attempts);
        $this->assertSame(1, $failed->events()->where('type', 'qualify_failed')->count());
    }

    public function test_a_failed_ai_answer_still_counts_toward_the_daily_cap(): void
    {
        $ai = $this->ai([null, '{"score": 10, "reason": "no"}', '{"score": 10, "reason": "no"}']);
        $profile = $this->profile(['daily_qualify_cap' => 2]);
        foreach (['a', 'b', 'c'] as $id) {
            $this->newLead($profile, $id);
        }

        $this->runFor($profile);
        $this->assertCount(2, $ai->calls);

        $this->runFor($profile);
        $this->assertCount(2, $ai->calls, 'the recorded failure used one of the two daily slots');
    }

    public function test_one_poison_lead_does_not_stop_the_others_and_the_lock_is_released(): void
    {
        $this->ai(['{"score": 90, "reason": "p"}', '{"score": 90, "reason": "g"}']);
        $profile = $this->profile();
        $poison = $this->newLead($profile, 'poison');
        $good = $this->newLead($profile, 'good');
        Lead::updating(function (Lead $model): void {
            if ($model->source_id === 'poison') {
                throw new \RuntimeException('secret detail ada@leak.test');
            }
        });

        $result = $this->runFor($profile);

        $this->assertSame(Lead::STATUS_QUALIFIED, $good->fresh()->status);
        $this->assertSame('good@acme.test', $good->fresh()->email);
        $this->assertSame(Lead::STATUS_NEW, $poison->fresh()->status);
        $this->assertSame(1, $poison->fresh()->qualify_attempts);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('unexpected error', $result['errors'][0]);
        $this->assertStringNotContainsString('leak.test', implode(' ', $result['errors']));
        $this->assertStringContainsString('unexpected error', $profile->fresh()->last_qualify_error);
        $this->assertTrue(Cache::lock("lead-qualify:{$profile->id}", 5)->get(), 'lock released');
    }

    public function test_a_lead_whose_reveal_blows_up_moves_to_the_back_and_does_not_block_the_others(): void
    {
        $this->ai([]);
        $profile = $this->profile();
        $poison = $this->newLead($profile, 'poison', ['status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_HOT]);
        $good = $this->newLead($profile, 'good', ['status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_HOT]);
        Lead::updating(function (Lead $model): void {
            if ($model->source_id === 'poison' && $model->isDirty('email')) {
                throw new \RuntimeException('boom');
            }
        });
        $before = $poison->fresh()->updated_at;
        $this->travel(5)->minutes();

        $result = $this->runFor($profile);

        $this->assertSame(1, $result['revealed']);
        $this->assertSame('good@acme.test', $good->fresh()->email);
        $this->assertNull($poison->fresh()->email);
        $this->assertTrue($poison->fresh()->updated_at->greaterThan($before));
        $this->assertStringContainsString('unexpected error', $result['errors'][0]);
    }

    public function test_hot_leads_get_scarce_credits_before_warm_ones(): void
    {
        $this->ai([]);
        $profile = $this->profile(['monthly_reveal_budget' => 1]);
        $warm = $this->newLead($profile, 'warm', ['status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_WARM]);
        $hot = $this->newLead($profile, 'hot', ['status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_HOT]);

        $this->runFor($profile);

        $this->assertSame('hot@acme.test', $hot->fresh()->email);
        $this->assertNull($warm->fresh()->email);
    }

    public function test_the_run_stops_when_its_time_budget_is_spent(): void
    {
        $ai = new class implements AiClient
        {
            public int $calls = 0;

            public function chat(array $messages, array $opts = []): ?AiResult
            {
                $this->calls++;
                Carbon::setTestNow(now()->addMinutes(9));

                return new AiResult('{"score": 90, "reason": "ok"}', 'scripted', 1, 1);
            }
        };
        $this->app->instance(AiClient::class, $ai);
        $profile = $this->profile();
        $first = $this->newLead($profile, 'first');
        $second = $this->newLead($profile, 'second');

        $result = $this->runFor($profile);

        $this->assertSame(1, $ai->calls);
        $this->assertSame(Lead::STATUS_QUALIFIED, $first->fresh()->status);
        $this->assertSame(Lead::STATUS_NEW, $second->fresh()->status);
        $this->assertContains('Run time limit reached; the rest continues next run.', $result['errors']);
    }

    public function test_leads_waiting_only_for_the_cap_do_not_use_up_the_batch_and_free_rejections_still_happen(): void
    {
        $ai = $this->ai(['{"score": 10, "reason": "no"}']);
        $profile = $this->profile(['daily_qualify_cap' => 1]);
        foreach (range(1, 25) as $i) {
            $this->newLead($profile, "n{$i}");
        }
        $intern = $this->newLead($profile, 'late-intern', ['title' => 'Intern']);

        $this->runFor($profile);

        $this->assertCount(1, $ai->calls);
        $this->assertSame(Lead::STATUS_REJECTED, $intern->fresh()->status);
    }

    public function test_at_most_one_batch_of_twenty_leads_is_processed_per_run(): void
    {
        $ai = $this->ai(array_fill(0, 30, '{"score": 10, "reason": "no"}'));
        $profile = $this->profile(['daily_qualify_cap' => 100]);
        foreach (range(1, 30) as $i) {
            $this->newLead($profile, "n{$i}");
        }

        $this->runFor($profile);

        $this->assertCount(20, $ai->calls);
    }

    public function test_a_waiting_lead_with_an_unknown_source_is_rejected_instead_of_clogging_the_queue(): void
    {
        $this->ai([]);
        $profile = $this->profile();
        $lead = $this->newLead($profile, 'x', ['source' => 'nosuch', 'status' => Lead::STATUS_QUALIFIED, 'tier' => Lead::TIER_HOT]);

        $this->runFor($profile);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_REJECTED, $lead->status);
        $this->assertSame('Unknown lead source', $lead->qualification_reason);
        $this->assertSame(1, $lead->events()->where('type', 'rejected')->count());
    }

    public function test_a_failed_event_write_leaves_the_lead_unqualified(): void
    {
        $this->ai(['{"score": 90, "reason": "a"}']);
        $profile = $this->profile();
        $lead = $this->newLead($profile, 'a');
        LeadEvent::creating(function (LeadEvent $event): void {
            if ($event->type === 'qualified') {
                throw new \RuntimeException('event store down');
            }
        });

        $this->runFor($profile);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertNull($lead->score);
        $this->assertNull($lead->email);
        $this->assertSame(0, LeadEvent::where('type', 'qualified')->count());
    }

    public function test_the_lock_is_released_after_a_run_and_after_an_exception_that_escapes(): void
    {
        $this->ai([]);
        $profile = $this->profile();

        $this->runFor($profile);
        $this->assertTrue(Cache::lock("lead-qualify:{$profile->id}", 5)->get(), 'released after a normal run');
        Cache::lock("lead-qualify:{$profile->id}")->forceRelease();

        Schema::rename('lead_events', 'lead_events_away');
        try {
            $this->runFor($profile);
            $this->fail('Expected the counting query to fail');
        } catch (QueryException) {
            // expected
        } finally {
            Schema::rename('lead_events_away', 'lead_events');
        }
        $this->assertTrue(Cache::lock("lead-qualify:{$profile->id}", 5)->get(), 'released after an exception');
    }

    public function test_the_daily_cap_resets_at_midnight_in_the_business_timezone(): void
    {
        config(['app.business_timezone' => 'Africa/Lagos']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 01:30', 'Africa/Lagos'));
        $ai = $this->ai(['{"score": 10, "reason": "no"}']);
        $profile = $this->profile(['daily_qualify_cap' => 1]);
        $old = $this->newLead($profile, 'old', ['status' => Lead::STATUS_REJECTED]);
        LeadEvent::record($old, 'rejected', [], 'ai')
            ->forceFill(['created_at' => Carbon::parse('2026-11-30 23:30', 'Africa/Lagos')->utc()])->save();
        $this->newLead($profile, 'fresh');

        $this->runFor($profile);

        $this->assertCount(1, $ai->calls, 'yesterday (Lagos) does not count against today');
    }

    public function test_an_ai_decision_made_today_in_the_business_timezone_uses_the_cap(): void
    {
        config(['app.business_timezone' => 'Africa/Lagos']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 01:30', 'Africa/Lagos'));
        $ai = $this->ai(['{"score": 10, "reason": "no"}']);
        $profile = $this->profile(['daily_qualify_cap' => 1]);
        $old = $this->newLead($profile, 'old', ['status' => Lead::STATUS_REJECTED]);
        LeadEvent::record($old, 'rejected', [], 'ai')
            ->forceFill(['created_at' => Carbon::parse('2026-12-01 00:15', 'Africa/Lagos')->utc()])->save();
        $this->newLead($profile, 'fresh');

        $this->runFor($profile);

        $this->assertCount(0, $ai->calls);
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
