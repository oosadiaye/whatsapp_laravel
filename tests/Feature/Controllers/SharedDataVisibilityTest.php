<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppInstance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the cross-user visibility contract for single-tenant data.
 *
 * Background: this app is single-tenant — one company on
 * blast.dpluxtech.com. But every controller historically scoped reads
 * with `where('user_id', auth()->id())`. That meant a newly-added admin
 * saw empty Contacts, empty Inbox, empty Campaigns, and empty Templates
 * on first login, even when the company had thousands of records
 * already.
 *
 * The fix is to remove the user_id WHERE clause from read paths across
 * Campaign / Contact / ContactGroup / Conversation / MessageTemplate /
 * WhatsAppInstance / Dashboard. Route-level permission middleware
 * remains the only authorization layer. The user_id column on each row
 * stays in the DB as audit metadata ("who created this first") but no
 * longer scopes lookups.
 *
 * This test file would fail if anyone re-introduces the
 * `where('user_id', auth()->id())` scope on any of these public list
 * endpoints.
 */
class SharedDataVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 200),
        ]);
    }

    public function test_new_admin_sees_contacts_created_by_other_users(): void
    {
        $original = $this->makeAdmin('original-contacts@example.com');
        Contact::factory()->count(3)->create(['user_id' => $original->id]);

        $newHire = $this->makeAdmin('new-hire-contacts@example.com');

        $response = $this->actingAs($newHire)->get(route('contacts.index'));

        $response->assertOk();
        // The view paginates 25 per page; 3 contacts should appear in the
        // page collection. Easier than asserting against the paginator
        // total — we just need to prove the contacts were visible.
        $contacts = $response->viewData('contacts');
        $this->assertSame(3, $contacts->total());
    }

    public function test_new_admin_sees_contact_groups_created_by_other_users(): void
    {
        $original = $this->makeAdmin('original-groups@example.com');
        ContactGroup::create(['user_id' => $original->id, 'name' => 'Shared Group A']);
        ContactGroup::create(['user_id' => $original->id, 'name' => 'Shared Group B']);

        $newHire = $this->makeAdmin('new-hire-groups@example.com');

        $response = $this->actingAs($newHire)->get(route('groups.index'));
        $response->assertOk();
        $this->assertCount(2, $response->viewData('groups'));
    }

    public function test_new_admin_sees_campaigns_created_by_other_users(): void
    {
        $original = $this->makeAdmin('original-campaigns@example.com');
        $instance = WhatsAppInstance::factory()->create(['user_id' => $original->id]);
        Campaign::create([
            'user_id' => $original->id,
            'instance_id' => $instance->id,
            'name' => 'Holiday Blast',
            'message' => 'hi',
            'status' => 'DRAFT',
        ]);

        $newHire = $this->makeAdmin('new-hire-campaigns@example.com');

        $response = $this->actingAs($newHire)->get(route('campaigns.index'));
        $response->assertOk();
        $response->assertSee('Holiday Blast');
    }

    public function test_new_admin_inbox_shows_conversations_created_by_other_users(): void
    {
        $original = $this->makeAdmin('original-conv@example.com');
        Conversation::factory()->count(4)->create(['user_id' => $original->id]);

        $newHire = $this->makeAdmin('new-hire-conv@example.com');

        $response = $this->actingAs($newHire)->get(route('conversations.index'));
        $response->assertOk();
        // Admin has conversations.view_all → sees every conversation.
        $this->assertCount(4, $response->viewData('conversations'));
    }

    public function test_dashboard_stats_count_across_all_users(): void
    {
        $original = $this->makeAdmin('original-dash@example.com');
        Contact::factory()->count(7)->create(['user_id' => $original->id]);
        $instance = WhatsAppInstance::factory()->create(['user_id' => $original->id]);
        Campaign::create([
            'user_id' => $original->id,
            'instance_id' => $instance->id,
            'name' => 'Stats test',
            'message' => 'hi',
            'status' => 'COMPLETED',
        ]);

        $newHire = $this->makeAdmin('new-hire-dash@example.com');

        $response = $this->actingAs($newHire)->get(route('dashboard'));
        $response->assertOk();
        // Both numbers should reflect account-wide totals, not the new
        // hire's "I have 0 of each" view.
        $this->assertSame(7, $response->viewData('totalContacts'));
        $this->assertSame(1, $response->viewData('totalCampaigns'));
    }

    public function test_agent_inbox_still_scoped_to_assigned_conversations(): void
    {
        // The agent workflow scope (conversations.view_assigned) is NOT
        // multi-tenant residue — it's per-agent task assignment. This must
        // still filter so agents only see conversations they own.
        $admin = $this->makeAdmin('admin-agentscope@example.com');
        $agent = User::factory()->create([
            'email' => 'agent-scope@example.com',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        // 5 conversations exist in the company. 1 assigned to this agent.
        $assigned = Conversation::factory()->assignedTo($agent)->create(['user_id' => $admin->id]);
        Conversation::factory()->count(4)->create([
            'user_id' => $admin->id,
            'assigned_to_user_id' => null,  // unassigned pool
        ]);

        $response = $this->actingAs($agent)->get(route('conversations.index'));
        $response->assertOk();

        // The agent's view_assigned scope only includes conversations
        // explicitly assigned to them — NOT every conversation in the
        // company. (The unassigned pool is exposed via the assignment
        // filter UI, not the default list.)
        $convs = $response->viewData('conversations');
        $this->assertCount(1, $convs);
        $this->assertSame($assigned->id, $convs->first()->id);
    }

    private function makeAdmin(?string $email = null): User
    {
        $admin = User::factory()->create([
            'email' => $email ?? 'admin-'.uniqid().'@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
