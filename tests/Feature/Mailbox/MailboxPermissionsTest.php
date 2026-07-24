<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan B2 / review M4 — the mailbox.* permission namespace. Default is
 * PRIVATE-per-user: everyone gets their OWN mailbox (mailbox.view), but reading
 * the team's inboxes (view_all) / managing others' accounts (admin) is
 * super_admin + admin only.
 */
class MailboxPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_agent_gets_own_mailbox_only(): void
    {
        $agent = $this->user('agent');
        $this->assertTrue($agent->can('mailbox.view'));
        $this->assertFalse($agent->can('mailbox.view_all'));
        $this->assertFalse($agent->can('mailbox.admin'));
    }

    public function test_manager_cannot_read_all_inboxes_or_administer_accounts(): void
    {
        $manager = $this->user('manager');
        $this->assertTrue($manager->can('mailbox.view'));
        $this->assertFalse($manager->can('mailbox.view_all'));
        $this->assertFalse($manager->can('mailbox.admin'));
    }

    public function test_admin_and_super_admin_have_full_mailbox_access(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $user = $this->user($role);
            $this->assertTrue($user->can('mailbox.view'), $role);
            $this->assertTrue($user->can('mailbox.view_all'), $role);
            $this->assertTrue($user->can('mailbox.admin'), $role);
        }
    }
}
