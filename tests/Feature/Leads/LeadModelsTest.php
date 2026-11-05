<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\LeadProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_casts_json_columns_and_reads_source_state(): void
    {
        $profile = LeadProfile::factory()->create([
            'hard_filters' => ['countries' => ['Nigeria']],
            'sources' => ['apollo'],
            'pull_state' => ['apollo' => ['next_page' => 3, 'exhausted' => false]],
        ]);

        $fresh = $profile->fresh();
        $this->assertSame(['Nigeria'], $fresh->hard_filters['countries']);
        $this->assertSame(['next_page' => 3, 'exhausted' => false], $fresh->sourceState('apollo'));
        $this->assertSame(['next_page' => 1, 'exhausted' => false], $fresh->sourceState('hunter'));
        $this->assertFalse($fresh->hasQuery());
    }

    public function test_leads_are_unique_per_source_and_source_id(): void
    {
        Lead::factory()->create(['source' => 'apollo', 'source_id' => 'abc']);

        $this->expectException(UniqueConstraintViolationException::class);
        Lead::factory()->create(['source' => 'apollo', 'source_id' => 'abc']);
    }

    public function test_many_leads_may_have_no_email(): void
    {
        Lead::factory()->count(2)->create(['email' => null]);

        $this->assertSame(2, Lead::whereNull('email')->count());
    }

    public function test_deleting_a_profile_keeps_its_leads(): void
    {
        $profile = LeadProfile::factory()->create();
        $lead = Lead::factory()->create(['lead_profile_id' => $profile->id]);

        $profile->delete();

        $this->assertNull($lead->fresh()->lead_profile_id);
    }

    public function test_events_record_type_payload_and_actor(): void
    {
        $lead = Lead::factory()->create();

        $event = LeadEvent::record($lead, 'pulled', ['profile_id' => 7], 'system');

        $this->assertSame('pulled', $event->type);
        $this->assertSame(['profile_id' => 7], $event->fresh()->payload);
        $this->assertSame($lead->id, $lead->events()->first()->lead_id);
    }
}
