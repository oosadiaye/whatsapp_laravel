<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadQualificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiles_get_sensible_defaults(): void
    {
        $profile = LeadProfile::factory()->create()->fresh();

        $this->assertSame(75, $profile->hot_threshold);
        $this->assertSame(50, $profile->warm_threshold);
        $this->assertSame(100, $profile->daily_qualify_cap);
        $this->assertSame(200, $profile->monthly_reveal_budget);
        $this->assertNull($profile->last_qualify_error);
    }

    public function test_leads_start_with_no_attempts_and_no_timestamps(): void
    {
        $lead = Lead::factory()->create()->fresh();

        $this->assertSame(0, $lead->qualify_attempts);
        $this->assertNull($lead->qualified_at);
        $this->assertNull($lead->revealed_at);
    }

    public function test_email_is_trimmed_and_lowercased_on_write(): void
    {
        $lead = Lead::factory()->create(['email' => '  Ada@AcmePay.TEST ']);

        $this->assertSame('ada@acmepay.test', $lead->fresh()->email);
    }

    public function test_blank_email_becomes_null(): void
    {
        $this->assertNull(Lead::factory()->create(['email' => '   '])->fresh()->email);
        $this->assertNull(Lead::factory()->create(['email' => null])->fresh()->email);
    }

    public function test_same_email_in_different_case_is_a_duplicate(): void
    {
        Lead::factory()->create(['email' => 'ada@acmepay.test']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Lead::factory()->create(['email' => 'ADA@acmepay.test']);
    }

    public function test_tier_constants(): void
    {
        $this->assertSame('hot', Lead::TIER_HOT);
        $this->assertSame('warm', Lead::TIER_WARM);
        $this->assertSame('rejected', Lead::TIER_REJECTED);
    }
}
