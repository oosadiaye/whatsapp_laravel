<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LeadPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function roles(): array
    {
        return [
            'super_admin' => ['super_admin', true],
            'admin' => ['admin', true],
            'manager' => ['manager', true],
            'agent' => ['agent', false],
        ];
    }

    #[DataProvider('roles')]
    public function test_which_roles_can_view_and_manage_leads(string $role, bool $allowed): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertSame($allowed, $user->can('leads.view'));
        $this->assertSame($allowed, $user->can('leads.manage'));
    }
}
