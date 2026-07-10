<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Jobs\CampaignBatchDispatch;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\MessageLog;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for the "contacts aren't adding to campaign" report.
 *
 * The user saw campaigns with status=QUEUED showing 0/0 sent and 0 total
 * contacts on the index page. This test pins down the entire contact-attach
 * chain end to end:
 *
 *   1. Form submission attaches contact_groups via campaign_group pivot
 *   2. Show page renders the attached groups with active-contact counts
 *   3. CampaignBatchDispatch fans those groups' active contacts out to
 *      MessageLog rows AND populates campaign.total_contacts
 *
 * Each step has its own test so regressions are localized.
 */
class CampaignContactAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_campaign_creation_attaches_contact_groups_to_pivot(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'VIPs']);
        Contact::factory()->count(3)->create(['user_id' => $admin->id])
            ->each(fn ($c) => $group->contacts()->attach($c->id));

        $this->actingAs($admin)
            ->post(route('campaigns.store'), [
                'name' => 'Test',
                'message' => 'Hi {{name}}',
                'instance_id' => $instance->id,
                'groups' => [$group->id],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $campaign = Campaign::first();
        $this->assertNotNull($campaign, 'Campaign was not created');
        $this->assertCount(1, $campaign->contactGroups, 'Group was not attached to campaign');
        $this->assertSame($group->id, $campaign->contactGroups->first()->id);
    }

    public function test_show_page_renders_attached_groups_with_active_contact_counts(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Beta testers']);

        // 5 active + 2 inactive contacts in the group
        $active = Contact::factory()->count(5)->create(['user_id' => $admin->id, 'is_active' => true]);
        $inactive = Contact::factory()->count(2)->create(['user_id' => $admin->id, 'is_active' => false]);
        foreach ($active->concat($inactive) as $c) {
            $group->contacts()->attach($c->id);
        }

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Beta blast',
            'message' => 'Hi',
            'status' => 'DRAFT',
        ]);
        $campaign->contactGroups()->attach($group->id);

        $response = $this->actingAs($admin)->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $response->assertSee('Beta testers');
        $response->assertSee('5 active');         // active count badge
        $response->assertSee('(7 total)', false); // total count parenthetical
        $response->assertSee('Estimated reach');  // section heading exists
    }

    public function test_show_page_warns_when_no_groups_attached(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Orphan',
            'message' => 'Hi',
            'status' => 'DRAFT',
        ]);

        $this->actingAs($admin)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('No recipients attached');
    }

    public function test_batch_dispatch_creates_message_log_per_active_contact_in_attached_groups(): void
    {
        Http::fake();
        Bus::fake([\App\Jobs\SendWhatsAppMessage::class]);

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);

        $active = Contact::factory()->count(4)->create(['user_id' => $admin->id, 'is_active' => true]);
        $inactive = Contact::factory()->count(1)->create(['user_id' => $admin->id, 'is_active' => false]);
        foreach ($active->concat($inactive) as $c) {
            $group->contacts()->attach($c->id);
        }

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Targets blast',
            'message' => 'Hi',
            'status' => 'QUEUED',
            'rate_per_minute' => 60,
            'delay_min' => 0,
            'delay_max' => 1,
        ]);
        $campaign->contactGroups()->attach($group->id);

        // Run the batch dispatcher synchronously
        (new CampaignBatchDispatch($campaign))->handle();

        $campaign->refresh();
        $this->assertSame('RUNNING', $campaign->status);
        $this->assertSame(4, $campaign->total_contacts, 'Should fan out only the 4 ACTIVE contacts (1 inactive excluded)');
        $this->assertSame(4, MessageLog::where('campaign_id', $campaign->id)->count(), 'One PENDING MessageLog per active contact');
        Bus::assertDispatchedTimes(\App\Jobs\SendWhatsAppMessage::class, 4);
    }

    public function test_batch_dispatch_with_no_groups_marks_campaign_completed_with_zero_contacts(): void
    {
        Http::fake();
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Empty',
            'message' => 'Hi',
            'status' => 'QUEUED',
        ]);

        (new CampaignBatchDispatch($campaign))->handle();

        $campaign->refresh();
        // No groups -> 0 contacts -> early-completes
        $this->assertSame('COMPLETED', $campaign->status);
        $this->assertSame(0, $campaign->total_contacts);
        $this->assertSame(0, MessageLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_batch_dispatch_bails_when_campaign_not_queued(): void
    {
        Http::fake();
        Bus::fake([\App\Jobs\SendWhatsAppMessage::class]);

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $group = ContactGroup::create(['user_id' => $admin->id, 'name' => 'Targets']);
        Contact::factory()->count(3)->create(['user_id' => $admin->id, 'is_active' => true])
            ->each(fn ($c) => $group->contacts()->attach($c->id));

        // Already RUNNING (e.g. a retried/duplicate dispatch): must NOT re-fan-out.
        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Running',
            'message' => 'Hi',
            'status' => 'RUNNING',
        ]);
        $campaign->contactGroups()->attach($group->id);

        (new CampaignBatchDispatch($campaign))->handle();

        $this->assertSame('RUNNING', $campaign->fresh()->status);
        $this->assertSame(0, MessageLog::where('campaign_id', $campaign->id)->count());
        Bus::assertNotDispatched(\App\Jobs\SendWhatsAppMessage::class);
    }

    public function test_batch_dispatch_failed_handler_marks_campaign_failed(): void
    {
        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Doomed',
            'message' => 'Hi',
            'status' => 'RUNNING',
        ]);

        (new CampaignBatchDispatch($campaign))->failed(new \RuntimeException('db timeout mid-fanout'));

        $this->assertSame('FAILED', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->completed_at);
    }

    public function test_batch_dispatch_counts_a_contact_in_multiple_groups_once(): void
    {
        Http::fake();
        Bus::fake([\App\Jobs\SendWhatsAppMessage::class]);

        $admin = $this->makeAdmin();
        $instance = WhatsAppInstance::factory()->create(['user_id' => $admin->id]);
        $groupA = ContactGroup::create(['user_id' => $admin->id, 'name' => 'A']);
        $groupB = ContactGroup::create(['user_id' => $admin->id, 'name' => 'B']);

        $shared = Contact::factory()->create(['user_id' => $admin->id, 'is_active' => true]);
        $groupA->contacts()->attach($shared->id);
        $groupB->contacts()->attach($shared->id);

        $campaign = Campaign::create([
            'user_id' => $admin->id,
            'instance_id' => $instance->id,
            'name' => 'Overlap',
            'message' => 'Hi',
            'status' => 'QUEUED',
            'rate_per_minute' => 60,
            'delay_min' => 0,
            'delay_max' => 1,
        ]);
        $campaign->contactGroups()->attach([$groupA->id, $groupB->id]);

        (new CampaignBatchDispatch($campaign))->handle();

        $this->assertSame(1, $campaign->fresh()->total_contacts, 'Shared contact should count once');
        $this->assertSame(1, MessageLog::where('campaign_id', $campaign->id)->count());
        Bus::assertDispatchedTimes(\App\Jobs\SendWhatsAppMessage::class, 1);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }
}
