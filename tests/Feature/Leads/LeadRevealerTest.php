<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use App\Services\Leads\LeadRevealer;
use App\Services\Leads\LeadSourceException;
use App\Support\LeadConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LeadRevealerTest extends TestCase
{
    use RefreshDatabase;

    private const MATCH = 'https://api.apollo.io/api/v1/people/match*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apollo.key' => null]);
        LeadConfig::setKey('apollo', 'apollo-test-key-123');
    }

    private function profile(array $overrides = []): LeadProfile
    {
        return LeadProfile::factory()->active()->withQuery()->create(array_merge(['monthly_reveal_budget' => 10], $overrides));
    }

    private function lead(LeadProfile $profile, array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'lead_profile_id' => $profile->id,
            'source' => 'apollo',
            'source_id' => 'p1',
            'status' => Lead::STATUS_QUALIFIED,
            'name' => 'Ada Ob***i',
        ], $overrides));
    }

    private function fakeReveal(array $person = []): void
    {
        Http::fake([self::MATCH => Http::response(['person' => array_merge([
            'name' => 'Ada Obi',
            'email' => 'Ada@AcmePay.test',
            'email_status' => 'verified',
            'country' => 'Nigeria',
            'linkedin_url' => 'https://linkedin.com/in/adaobi',
            'organization' => ['name' => 'Acme Pay', 'primary_domain' => 'acmepay.test'],
        ], $person)])]);
    }

    private function revealer(): LeadRevealer
    {
        return app(LeadRevealer::class);
    }

    public function test_a_successful_reveal_stores_the_email_and_details_and_counts_a_credit(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        $lead = $this->lead($profile);

        $outcome = $this->revealer()->reveal($lead, $profile);

        $this->assertSame(LeadRevealer::REVEALED, $outcome);
        $lead->refresh();
        $this->assertSame('ada@acmepay.test', $lead->email);
        $this->assertSame('verified', $lead->email_status);
        $this->assertSame('Ada Obi', $lead->name);
        $this->assertSame('acmepay.test', $lead->domain);
        $this->assertSame('Nigeria', $lead->country);
        $this->assertNotNull($lead->revealed_at);
        $this->assertSame(Lead::STATUS_QUALIFIED, $lead->status);
        $this->assertSame(1, $this->revealer()->creditsUsedThisMonth($profile));
        $this->assertSame('revealed', $lead->events()->latest('id')->first()->type);
    }

    public function test_a_lead_is_never_revealed_twice(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        $lead = $this->lead($profile);

        $this->revealer()->reveal($lead, $profile);
        $second = $this->revealer()->reveal($lead->fresh(), $profile);

        $this->assertSame(LeadRevealer::SKIPPED, $second);
        Http::assertSentCount(1);
    }

    public function test_leads_the_platform_has_no_email_for_are_skipped_without_a_call(): void
    {
        Http::fake();
        $profile = $this->profile();
        $lead = $this->lead($profile, ['email_status' => Lead::EMAIL_UNAVAILABLE]);

        $this->assertSame(LeadRevealer::SKIPPED, $this->revealer()->reveal($lead, $profile));
        Http::assertNothingSent();
    }

    public function test_the_monthly_budget_is_a_hard_ceiling(): void
    {
        Http::fake();
        $profile = $this->profile(['monthly_reveal_budget' => 1]);
        $earlier = $this->lead($profile, ['source_id' => 'old']);
        LeadEvent::record($earlier, 'revealed', ['credits' => 1]);
        $lead = $this->lead($profile, ['source_id' => 'new']);

        $this->assertSame(LeadRevealer::BUDGET, $this->revealer()->reveal($lead, $profile));
        Http::assertNothingSent();
        $this->assertNull($lead->fresh()->revealed_at);
    }

    public function test_a_zero_budget_disables_revealing(): void
    {
        Http::fake();
        $profile = $this->profile(['monthly_reveal_budget' => 0]);

        $this->assertSame(LeadRevealer::BUDGET, $this->revealer()->reveal($this->lead($profile), $profile));
        Http::assertNothingSent();
    }

    public function test_last_months_credits_do_not_count_and_other_profiles_do_not_share_the_budget(): void
    {
        $this->fakeReveal();
        $profile = $this->profile(['monthly_reveal_budget' => 1]);
        $other = $this->profile(['monthly_reveal_budget' => 1]);

        $old = LeadEvent::record($this->lead($profile, ['source_id' => 'old']), 'revealed', ['credits' => 1]);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();
        LeadEvent::record($this->lead($other, ['source_id' => 'theirs']), 'revealed', ['credits' => 1]);

        $this->assertSame(0, $this->revealer()->creditsUsedThisMonth($profile));
        $this->assertSame(LeadRevealer::REVEALED, $this->revealer()->reveal($this->lead($profile, ['source_id' => 'mine']), $profile));
    }

    public function test_no_email_returned_rejects_the_lead_and_costs_no_credit(): void
    {
        $this->fakeReveal(['email' => null]);
        $profile = $this->profile();
        $lead = $this->lead($profile);

        $this->assertSame(LeadRevealer::NO_EMAIL, $this->revealer()->reveal($lead, $profile));

        $lead->refresh();
        $this->assertSame(Lead::STATUS_REJECTED, $lead->status);
        $this->assertSame(Lead::EMAIL_UNAVAILABLE, $lead->email_status);
        $this->assertNull($lead->email);
        $this->assertSame(0, $this->revealer()->creditsUsedThisMonth($profile));
        $this->assertSame('reveal_empty', $lead->events()->latest('id')->first()->type);
    }

    public function test_an_email_that_belongs_to_another_lead_is_a_duplicate_and_is_not_stored(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        $existing = $this->lead($profile, ['source_id' => 'first', 'email' => 'ada@acmepay.test']);
        $lead = $this->lead($profile, ['source_id' => 'second']);

        $this->assertSame(LeadRevealer::DUPLICATE, $this->revealer()->reveal($lead, $profile));

        $lead->refresh();
        $this->assertSame(Lead::STATUS_REJECTED, $lead->status);
        $this->assertNull($lead->email);
        $this->assertStringContainsString('#'.$existing->id, $lead->qualification_reason);
        $this->assertSame('ada@acmepay.test', $existing->fresh()->email);
        $this->assertSame(1, $this->revealer()->creditsUsedThisMonth($profile), 'the credit was spent');
    }

    public function test_an_email_that_is_already_a_contact_is_rejected_even_if_the_contact_was_deleted(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        Contact::factory()->create(['email' => 'ADA@acmepay.test'])->delete();
        $lead = $this->lead($profile);

        $this->assertSame(LeadRevealer::DUPLICATE, $this->revealer()->reveal($lead, $profile));
        $this->assertStringContainsString('contact', strtolower($lead->fresh()->qualification_reason));
        $this->assertNull($lead->fresh()->email);
    }

    public function test_a_suppressed_address_is_rejected(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        EmailSuppression::suppress('ada@acmepay.test', EmailSuppression::REASON_UNSUBSCRIBE);
        $lead = $this->lead($profile);

        $this->assertSame(LeadRevealer::DUPLICATE, $this->revealer()->reveal($lead, $profile));
        $this->assertStringContainsString('suppress', strtolower($lead->fresh()->qualification_reason));
        $this->assertNull($lead->fresh()->email);
    }

    public function test_a_unique_violation_while_saving_is_treated_as_a_duplicate_not_a_crash(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();
        $lead = $this->lead($profile);

        Lead::updating(function (Lead $model): void {
            if ($model->isDirty('email')) {
                throw new UniqueConstraintViolationException('sqlite', 'update leads', [], new \Exception('duplicate'));
            }
        });

        try {
            $outcome = $this->revealer()->reveal($lead, $profile);
        } finally {
            Lead::flushEventListeners();
        }

        $this->assertSame(LeadRevealer::DUPLICATE, $outcome);
        $this->assertSame(Lead::STATUS_REJECTED, $lead->fresh()->status);
    }

    public function test_a_platform_error_propagates_and_changes_nothing(): void
    {
        Http::fake([self::MATCH => Http::response('no', 401)]);
        $profile = $this->profile();
        $lead = $this->lead($profile);

        try {
            $this->revealer()->reveal($lead, $profile);
            $this->fail('Expected LeadSourceException');
        } catch (LeadSourceException $e) {
            $this->assertStringContainsString('API key', $e->getMessage());
        }

        $lead->refresh();
        $this->assertSame(Lead::EMAIL_UNREVEALED, $lead->email_status);
        $this->assertNull($lead->revealed_at);
        $this->assertSame(0, $this->revealer()->creditsUsedThisMonth($profile));
    }

    public function test_the_request_asks_for_the_lead_by_its_platform_id(): void
    {
        $this->fakeReveal();
        $profile = $this->profile();

        $this->revealer()->reveal($this->lead($profile, ['source_id' => 'abc123']), $profile);

        Http::assertSent(fn (Request $r): bool => str_contains(urldecode($r->url()), 'id=abc123'));
    }
}
