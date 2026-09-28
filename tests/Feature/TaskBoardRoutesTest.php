<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\TaskActivity;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level coverage for the task board's route surface.
 *
 * The Livewire/component and controller tests exercise behaviour, but nothing
 * covered the full chain these pages actually travel: route registration ->
 * `permission:` middleware -> controller -> view. A typo'd route name, a
 * missing view, or a route left off the permission middleware would render a
 * green suite and still 500 or leak in the browser, so these tests walk the
 * real routes.
 */
class TaskBoardRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function makeUser(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A user with no tasks.* permission at all.
     *
     * revokePermissionTo() strips only DIRECTLY-assigned permissions, so it
     * cannot revoke a role grant. Negative tests need a role-less user.
     */
    private function makeUnprivilegedUser(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function makeBoard(User $owner): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
    }

    /**
     * A user holding `tasks.view` but not `tasks.create`.
     *
     * No seeded role has exactly this shape (agent gets view+create), so it is
     * granted directly to pin down the "viewer, cannot build boards" branch of
     * the /tasks entry point.
     */
    private function makeViewOnlyUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('tasks.view');

        return $user;
    }

    public function test_board_screen_renders_over_http_for_a_viewer(): void
    {
        $user = $this->makeUser('agent');
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/boards/'.$board->id)
            ->assertOk();
    }

    public function test_board_screen_is_forbidden_without_tasks_view(): void
    {
        $user = $this->makeUnprivilegedUser();
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/boards/'.$board->id)
            ->assertForbidden();
    }

    public function test_board_screen_requires_authentication(): void
    {
        $board = $this->makeBoard(User::factory()->create(['is_active' => true]));

        $this->get('/boards/'.$board->id)
            ->assertRedirect(route('login'));
    }

    public function test_board_index_renders_for_a_viewer(): void
    {
        $user = $this->makeUser('agent');
        $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/boards')
            ->assertOk();
    }

    public function test_board_create_screen_is_gated_on_tasks_create(): void
    {
        $this->actingAs($this->makeUnprivilegedUser())
            ->get('/boards/create')
            ->assertForbidden();

        // Agents may create cards, so they may also stand up a board.
        $this->actingAs($this->makeUser('agent'))
            ->get('/boards/create')
            ->assertOk();
    }

    public function test_tasks_entry_point_never_errors_and_writes_nothing(): void
    {
        $user = $this->makeUser('super_admin');
        Board::query()->delete();

        $this->actingAs($user)
            ->get('/tasks')
            ->assertRedirect(route('boards.create'));

        $this->assertSame(0, Board::query()->count(), 'GET /tasks must not create a board.');
    }

    public function test_tasks_entry_point_sends_view_only_users_to_the_board_list(): void
    {
        $user = $this->makeViewOnlyUser();
        Board::query()->delete();

        $this->actingAs($user)
            ->get('/tasks')
            ->assertRedirect(route('boards.index'));

        $this->assertSame(0, Board::query()->count(), 'GET /tasks must not create a board.');
    }

    public function test_tasks_entry_point_renders_the_first_board_when_one_exists(): void
    {
        $user = $this->makeUser('agent');
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk()
            ->assertSee($board->name);
    }

    public function test_status_management_screen_is_withheld_from_agents(): void
    {
        $this->actingAs($this->makeUser('agent'))
            ->get('/task-statuses')
            ->assertForbidden();
    }

    public function test_status_management_screen_renders_for_a_manager(): void
    {
        $this->actingAs($this->makeUser('manager'))
            ->get('/task-statuses')
            ->assertOk();
    }

    public function test_status_management_screen_requires_authentication(): void
    {
        $this->get('/task-statuses')
            ->assertRedirect(route('login'));
    }

    public function test_report_renders_for_a_manager(): void
    {
        $user = $this->makeUser('manager');
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/boards/'.$board->id.'/report')
            ->assertOk()
            ->assertSee($board->name);
    }

    public function test_report_is_withheld_from_agents(): void
    {
        $user = $this->makeUser('agent');
        $board = $this->makeBoard($user);

        // The page reports on the team — per-person load, how long work sits in
        // a column. An agent who can work cards does not get a view of everyone's
        // workload, which is why this is tasks.report and not tasks.view.
        $this->actingAs($user)
            ->get('/boards/'.$board->id.'/report')
            ->assertForbidden();
    }

    public function test_report_is_forbidden_without_tasks_report(): void
    {
        $user = $this->makeViewOnlyUser();
        $board = $this->makeBoard($user);

        $this->actingAs($user)
            ->get('/boards/'.$board->id.'/report')
            ->assertForbidden();
    }

    public function test_report_requires_authentication(): void
    {
        $board = $this->makeBoard(User::factory()->create(['is_active' => true]));

        $this->get('/boards/'.$board->id.'/report')
            ->assertRedirect(route('login'));
    }

    public function test_report_window_is_clamped_rather_than_trusted(): void
    {
        $user = $this->makeUser('manager');
        $board = $this->makeBoard($user);

        // The window renders one row per week, so an unbounded value from the
        // query string is a page that cannot load rather than a bigger report.
        foreach (['0', '-5', '5000', 'not-a-number'] as $value) {
            $this->actingAs($user)
                ->get('/boards/'.$board->id.'/report?weeks='.$value)
                ->assertOk();
        }
    }

    public function test_report_never_writes(): void
    {
        $user = $this->makeUser('manager');
        $board = $this->makeBoard($user);

        $before = [
            'boards' => $board->newQuery()->count(),
            'activities' => TaskActivity::query()->count(),
        ];

        $this->actingAs($user)->get('/boards/'.$board->id.'/report')->assertOk();
        $this->actingAs($user)->get('/boards/'.$board->id.'/report?weeks=12')->assertOk();

        $this->assertSame($before['boards'], $board->newQuery()->count());
        $this->assertSame($before['activities'], TaskActivity::query()->count());
    }
}
