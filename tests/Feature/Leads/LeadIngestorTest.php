<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProfile;
use App\Services\Leads\LeadData;
use App\Services\Leads\LeadIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadIngestorTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $id, array $overrides = []): LeadData
    {
        return (new LeadData(
            source: 'apollo',
            sourceId: $id,
            name: 'Ada Ob***i',
            title: 'CEO',
            company: 'Acme Pay',
            raw: ['id' => $id],
        ))->with($overrides);
    }

    public function test_new_leads_are_stored_against_the_profile_with_a_pulled_event(): void
    {
        $profile = LeadProfile::factory()->create();

        $result = app(LeadIngestor::class)->ingest($profile, [$this->data('a'), $this->data('b')]);

        $this->assertSame(['created' => 2, 'skipped' => 0], $result);

        $lead = Lead::where('source_id', 'a')->firstOrFail();
        $this->assertSame($profile->id, $lead->lead_profile_id);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertSame(Lead::EMAIL_UNREVEALED, $lead->email_status);
        $this->assertSame('Acme Pay', $lead->company);
        $this->assertSame('pulled', $lead->events()->first()->type);
        $this->assertSame($profile->id, $lead->events()->first()->payload['profile_id']);
    }

    public function test_a_lead_already_stored_is_skipped_not_duplicated(): void
    {
        $profile = LeadProfile::factory()->create();
        $ingestor = app(LeadIngestor::class);
        $ingestor->ingest($profile, [$this->data('a')]);

        $result = $ingestor->ingest($profile, [$this->data('a'), $this->data('c')]);

        $this->assertSame(['created' => 1, 'skipped' => 1], $result);
        $this->assertSame(2, Lead::count());
    }

    public function test_the_same_person_found_by_another_profile_is_not_pulled_twice(): void
    {
        $first = LeadProfile::factory()->create();
        $second = LeadProfile::factory()->create();
        $ingestor = app(LeadIngestor::class);
        $ingestor->ingest($first, [$this->data('a')]);

        $result = $ingestor->ingest($second, [$this->data('a')]);

        $this->assertSame(['created' => 0, 'skipped' => 1], $result);
        $this->assertSame($first->id, Lead::firstOrFail()->lead_profile_id);
    }

    public function test_an_empty_batch_is_fine(): void
    {
        $this->assertSame(['created' => 0, 'skipped' => 0], app(LeadIngestor::class)->ingest(LeadProfile::factory()->create(), []));
    }
}
