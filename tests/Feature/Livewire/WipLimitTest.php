<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\TaskBoard;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WIP ceilings: advisory, never a block.
 *
 * The tests that matter here are the negative ones. A ceiling that refuses a
 * move is a plausible-looking feature that quietly pushes a team to work around
 * the board, so the guarantee being pinned is that the move happens AND the
 * warning appears.
 */
class WipLimitTest extends TestCase
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

    private function makeBoard(User $owner): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
    }

    private function makeTask(User $owner, Board $board, string $status, string $title = 'A card'): Task
    {
        // A unique position within the column: the real controller reserves one,
        // but the seeder creates directly and the schema now forbids two cards
        // sharing a (board, status, position). max+1 keeps creation order.
        $position = (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => $title,
            'status' => $status,
            'position' => $position,
        ]);
    }

    private function setLimit(string $slug, ?int $limit): void
    {
        TaskStatus::where('slug', $slug)->firstOrFail()->forceFill(['wip_limit' => $limit])->save();
    }

    public function test_no_badge_is_shown_on_a_column_without_a_limit(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->makeTask($user, $board, 'in_progress');

        // A board nobody has constrained should look exactly as it did before
        // the feature existed.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertDontSee('1/3')
            ->assertSee('1');
    }

    public function test_the_badge_shows_occupancy_against_the_limit(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 3);
        $this->makeTask($user, $board, 'in_progress');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('1/3');
    }

    public function test_an_agent_sees_the_badge_too(): void
    {
        // The ceiling is a team constraint, not a management secret. An agent
        // being told to finish work instead of starting it needs to see it.
        $user = $this->makeUser('agent');
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 2);
        $this->makeTask($user, $board, 'in_progress');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('1/2');
    }

    public function test_the_badge_counts_every_card_even_while_filtered(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 3);

        $other = $this->makeUser();
        $this->makeTask($user, $board, 'in_progress', 'Mine');
        $this->makeTask($other, $board, 'in_progress', 'Theirs');

        // "Only mine" hides one of the two cards. A ceiling measured against the
        // visible subset would read 1/3 and make an over-limit column look
        // compliant - which is the exact failure the limit exists to prevent.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('Theirs')
            ->call('toggleOnlyMine')
            ->assertSee('Mine')
            ->assertDontSee('Theirs')
            ->assertSee('2/3');
    }

    public function test_moving_a_card_over_the_limit_warns_but_still_moves(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 2);
        $this->makeTask($user, $board, 'in_progress', 'First');
        $this->makeTask($user, $board, 'in_progress', 'Second');
        $task = $this->makeTask($user, $board, 'todo', 'Third');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'in_progress', 1)
            ->assertHasNoErrors()
            // The move is not refused. The ceiling is advice, not a gate.
            ->assertSet('wipNotice', fn (?string $n): bool => $n !== null
                && str_contains($n, 'In Progress')
                && str_contains($n, '3 of 2'));

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_moving_a_card_within_the_limit_says_nothing(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 3);
        $task = $this->makeTask($user, $board, 'todo');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'in_progress', 1)
            ->assertHasNoErrors()
            ->assertSet('wipNotice', null);
    }

    public function test_a_later_move_clears_a_stale_notice(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 1);
        $this->makeTask($user, $board, 'in_progress', 'Existing');
        $task = $this->makeTask($user, $board, 'todo', 'Incoming');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'in_progress', 1)
            ->assertSet('wipNotice', fn (?string $n): bool => $n !== null
                && str_contains($n, '2 of 1'))
            // Draining the column must take the warning with it, or a stale
            // notice outlives the pile-up it was describing.
            ->call('moveTask', $task->id, 'todo', 1)
            ->assertSet('wipNotice', null);
    }

    public function test_the_notice_can_be_dismissed(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 1);
        $this->makeTask($user, $board, 'in_progress', 'Existing');
        $task = $this->makeTask($user, $board, 'todo', 'Incoming');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'in_progress', 1)
            ->assertSet('wipNotice', fn (?string $n): bool => $n !== null)
            ->call('dismissWipNotice')
            ->assertSet('wipNotice', null);
    }

    public function test_creating_a_card_into_an_over_limit_column_warns(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 1);
        $this->makeTask($user, $board, 'in_progress', 'Existing');

        // The other way a ceiling gets breached without anyone dragging
        // anything: the create form lets you pick a column.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Another')
            ->set('newTaskStatus', 'in_progress')
            ->call('createTask')
            ->assertHasNoErrors()
            ->assertSet('wipNotice', fn (?string $n): bool => $n !== null
                && str_contains($n, '2 of 1'));

        $this->assertDatabaseHas('tasks', ['title' => 'Another', 'status' => 'in_progress']);
    }

    public function test_a_finished_column_never_warns(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        // "At most 3 in Done" is a misconfiguration, and a permanently red Done
        // column would train people to ignore the badge everywhere else.
        $this->setLimit('done', 1);
        $task = $this->makeTask($user, $board, 'todo');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'done', 1)
            ->assertHasNoErrors()
            ->assertSet('wipNotice', null)
            ->assertDontSee('1/1');
    }

    public function test_switching_boards_drops_the_notice(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $other = Board::create([
            'user_id' => $user->id,
            'name' => 'Billing',
            'slug' => 'billing',
        ]);

        $this->setLimit('in_progress', 1);
        $this->makeTask($user, $board, 'in_progress', 'Existing');
        $task = $this->makeTask($user, $board, 'todo', 'Incoming');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'in_progress', 1)
            ->assertSet('wipNotice', fn (?string $n): bool => $n !== null)
            // A notice about another board's columns is worse than no notice.
            ->call('selectBoard', $other->id)
            ->assertSet('wipNotice', null);
    }

    public function test_cards_on_another_board_do_not_count_towards_the_limit(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $other = Board::create([
            'user_id' => $user->id,
            'name' => 'Billing',
            'slug' => 'billing',
        ]);

        $this->setLimit('in_progress', 2);
        $this->makeTask($user, $other, 'in_progress', 'Elsewhere');
        $this->makeTask($user, $board, 'in_progress', 'Here');

        // The ceiling is a per-column number, but it is measured per board - a
        // busy team on another board must not make this one look over.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('1/2');
    }

    public function test_a_soft_deleted_card_stops_counting_towards_the_limit(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->setLimit('in_progress', 1);
        $task = $this->makeTask($user, $board, 'in_progress');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSee('1/1');

        $task->delete();

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('0/1');
    }
}
