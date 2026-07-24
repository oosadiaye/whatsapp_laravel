<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\EmailCampaignDispatch;
use App\Models\ContactGroup;
use App\Models\EmailCampaign;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->group = ContactGroup::create(['user_id' => $this->admin->id, 'name' => 'Prospects']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring outreach',
            'subject' => 'A quick hello',
            'body_html' => '<p>Hi {{name}}</p>',
            'groups' => [$this->group->id],
            'rate_per_minute' => 60,
            'action' => 'draft',
        ], $overrides);
    }

    public function test_index_requires_email_view_permission(): void
    {
        $this->actingAs($this->admin)->get(route('email-campaigns.index'))->assertOk();

        $noRole = User::factory()->create(['is_active' => true]);
        $this->actingAs($noRole)->get(route('email-campaigns.index'))->assertForbidden();
    }

    public function test_the_create_and_edit_pages_render(): void
    {
        // Regression: _form.blade.php had a Blade ParseError (nested {{ }}), so
        // the whole create/edit form 500'd. The store tests never render the GET
        // view, so this guards the form actually compiles.
        $this->actingAs($this->admin)->get(route('email-campaigns.create'))->assertOk();

        $campaign = EmailCampaign::create([
            'user_id' => $this->admin->id,
            'name' => 'Draft',
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $this->actingAs($this->admin)->get(route('email-campaigns.edit', $campaign))->assertOk();
    }

    public function test_the_create_page_offers_starter_templates(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-campaigns.create'))
            ->assertOk()
            ->assertSee('Start from a beautiful template')
            ->assertSee('Announcement')
            ->assertSee('Newsletter');
    }

    public function test_store_saves_a_draft_with_its_groups(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('email-campaigns.store'), $this->payload())
            ->assertRedirect();

        $campaign = EmailCampaign::first();
        $this->assertNotNull($campaign);
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $campaign->status);
        $this->assertTrue($campaign->contactGroups->contains($this->group));
        Queue::assertNotPushed(EmailCampaignDispatch::class);
    }

    public function test_store_with_send_action_launches_immediately(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('email-campaigns.store'), $this->payload(['action' => 'send']))
            ->assertRedirect();

        $this->assertSame(EmailCampaign::STATUS_QUEUED, EmailCampaign::first()->status);
        Queue::assertPushed(EmailCampaignDispatch::class);
    }

    public function test_store_with_schedule_action_sets_scheduled(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('email-campaigns.store'), $this->payload([
                'action' => 'schedule',
                'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'recurrence' => 'weekly',
            ]))
            ->assertRedirect();

        $campaign = EmailCampaign::first();
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->status);
        $this->assertNotNull($campaign->scheduled_at);
        $this->assertSame('weekly', $campaign->recurrence);
        Queue::assertNotPushed(EmailCampaignDispatch::class);
    }

    public function test_show_renders(): void
    {
        $campaign = EmailCampaign::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('email-campaigns.show', $campaign))
            ->assertOk()
            ->assertSee($campaign->subject);
    }
}
