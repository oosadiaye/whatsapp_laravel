<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadProfile;
use App\Models\LeadReply;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The `user` role: every feature, but a workspace of their own that a manager can open up. */
final class OwnRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail_client.enabled' => true]);
    }

    private function person(string $role, bool $restricted = false): User
    {
        $user = User::factory()->create(['is_active' => true, 'role' => $role, 'restrict_to_own' => $restricted]);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_user_role_gets_operational_features_but_no_admin_or_team_wide_access(): void
    {
        $user = $this->person(User::ROLE_USER);

        foreach (['contacts.create', 'campaigns.launch', 'templates.create', 'email.send', 'leads.manage', 'tasks.edit', 'mailbox.view', 'conversations.reply'] as $permission) {
            $this->assertTrue($user->can($permission), "user should have {$permission}");
        }
        foreach (['users.view', 'users.scope', 'settings.edit', 'mailbox.view_all', 'conversations.view_all', 'reports.view'] as $permission) {
            $this->assertFalse($user->can($permission), "user should not have {$permission}");
        }
    }

    public function test_a_restricted_user_sees_only_the_records_they_created(): void
    {
        $me = $this->person(User::ROLE_USER, restricted: true);
        $other = $this->person(User::ROLE_USER, restricted: true);
        $mine = Contact::factory()->create(['user_id' => $me->id]);
        Contact::factory()->create(['user_id' => $other->id]);

        $this->actingAs($me);

        $this->assertSame([$mine->id], Contact::query()->pluck('id')->all());
        $this->assertNull(Contact::query()->find(Contact::withoutGlobalScopes()->where('user_id', $other->id)->value('id')));
    }

    public function test_an_unrestricted_user_sees_everyones_records(): void
    {
        $me = $this->person(User::ROLE_USER, restricted: false);
        Contact::factory()->create(['user_id' => $me->id]);
        Contact::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($me);

        $this->assertSame(2, Contact::query()->count());
    }

    public function test_background_work_with_nobody_signed_in_is_never_narrowed(): void
    {
        $someone = $this->person(User::ROLE_USER, restricted: true);
        Contact::factory()->create(['user_id' => $someone->id]);
        Contact::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->assertSame(2, Contact::query()->count());
    }

    public function test_a_super_admin_is_never_confined_even_if_flagged(): void
    {
        $owner = $this->person(User::ROLE_SUPER_ADMIN, restricted: true);
        Contact::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($owner);

        $this->assertSame(1, Contact::query()->count());
    }

    public function test_a_restricted_user_sees_only_leads_from_their_own_profiles(): void
    {
        $me = $this->person(User::ROLE_USER, restricted: true);
        $mine = Lead::factory()->create(['lead_profile_id' => LeadProfile::factory()->create(['created_by' => $me->id])->id]);
        $theirs = Lead::factory()->create(['lead_profile_id' => LeadProfile::factory()->create(['created_by' => User::factory()->create()->id])->id]);
        $theirReply = LeadReply::create(['lead_id' => $theirs->id, 'email_message_id' => EmailMessage::factory()->create()->id, 'status' => LeadReply::STATUS_NEW]);

        $this->actingAs($me);

        $this->assertSame([$mine->id], Lead::query()->pluck('id')->all());
        $this->assertSame(1, LeadProfile::query()->count());
        $this->assertNull(LeadReply::query()->find($theirReply->id));
    }

    public function test_a_restricted_user_only_sees_their_own_mailbox(): void
    {
        $me = $this->person(User::ROLE_USER, restricted: true);
        EmailAccount::factory()->for($me)->create(['email' => 'mine@work.test']);
        EmailAccount::factory()->create(['email' => 'theirs@work.test']);

        $this->actingAs($me)->get(route('mailbox.accounts.index'))
            ->assertOk()
            ->assertSee('mine@work.test')
            ->assertDontSee('theirs@work.test');
    }

    public function test_creating_a_user_in_the_user_role_starts_them_restricted(): void
    {
        $admin = $this->person(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Hire', 'email' => 'hire@example.test', 'password' => 'password-123', 'role' => User::ROLE_USER,
        ])->assertRedirect(route('users.index'));

        $this->assertTrue(User::where('email', 'hire@example.test')->firstOrFail()->restrict_to_own);
    }

    public function test_a_manager_can_lift_and_restore_a_users_restriction(): void
    {
        $manager = $this->person(User::ROLE_MANAGER);
        $worker = $this->person(User::ROLE_USER, restricted: true);

        $this->actingAs($manager)->get(route('team-access.index'))->assertOk()->assertSee($worker->email);

        $this->actingAs($manager)->patch(route('team-access.update', $worker), ['restrict_to_own' => 0])->assertRedirect();
        $this->assertFalse($worker->fresh()->restrict_to_own);

        $this->actingAs($manager)->patch(route('team-access.update', $worker), ['restrict_to_own' => 1])->assertRedirect();
        $this->assertTrue($worker->fresh()->restrict_to_own);
    }

    public function test_a_manager_cannot_change_their_own_or_a_seniors_access(): void
    {
        $manager = $this->person(User::ROLE_MANAGER);
        $admin = $this->person(User::ROLE_ADMIN);

        $this->actingAs($manager)->patch(route('team-access.update', $manager), ['restrict_to_own' => 1])->assertForbidden();
        $this->actingAs($manager)->patch(route('team-access.update', $admin), ['restrict_to_own' => 1])->assertForbidden();
        $this->assertFalse($admin->fresh()->restrict_to_own);
    }

    public function test_a_user_cannot_reach_the_team_access_page(): void
    {
        $worker = $this->person(User::ROLE_USER, restricted: true);

        $this->actingAs($worker)->get(route('team-access.index'))->assertForbidden();
        $this->actingAs($worker)->patch(route('team-access.update', $worker), ['restrict_to_own' => 0])->assertForbidden();
        $this->assertTrue($worker->fresh()->restrict_to_own);
    }
}
