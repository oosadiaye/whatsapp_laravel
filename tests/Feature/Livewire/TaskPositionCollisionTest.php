<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\TaskBoard;
use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Position/unique-index collision regressions (review #2 and #3). Both are
 * deterministic 500s on ordinary actions that the original 233-test suite did not
 * cover (it exercised manual-mode drags and live-card concurrency, never these two
 * paths). Each test fails on the pre-fix code and passes after.
 */
class TaskPositionCollisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function boardOwnedBy(User $user): Board
    {
        return Board::create(['user_id' => $user->id, 'name' => 'Support', 'slug' => 'support']);
    }

    private function card(User $user, Board $board, string $status, int $position, string $title): Task
    {
        return Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => $title,
            'status' => $status,
            'position' => $position,
        ]);
    }

    public function test_adding_a_card_after_deleting_the_last_one_in_a_column_does_not_collide(): void
    {
        // #3: a soft-deleted card keeps its position under the unique index, but the
        // count/shift queries skipped trashed rows — so appending recomputed the
        // trashed card's exact position and 500'd on every retry.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        $board = $this->boardOwnedBy($user);

        $this->card($user, $board, 'todo', 1, 'Card 1');
        $this->card($user, $board, 'todo', 2, 'Card 2');
        $last = $this->card($user, $board, 'todo', 3, 'Card 3');

        $component = Livewire::test(TaskBoard::class, ['board' => $board]);
        $component->call('deleteTask', $last->id)->assertHasNoErrors();

        // The bug: this create used to throw a QueryException (unique violation) → 500.
        $component->set('title', 'Fresh card')
            ->set('newTaskStatus', 'todo')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'board_id' => $board->id, 'status' => 'todo', 'title' => 'Fresh card', 'deleted_at' => null,
        ]);
    }

    public function test_dragging_a_card_between_columns_in_a_sorted_view_does_not_collide(): void
    {
        // #2: in a sorted view moveTask did a status-only update, leaving the card's
        // old position — which collided with the destination column's card at that
        // same position on the unique index (uncaught → 500).
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        $board = $this->boardOwnedBy($user);

        $this->card($user, $board, 'todo', 1, 'T1');
        $moving = $this->card($user, $board, 'todo', 2, 'Mover');
        $this->card($user, $board, 'done', 1, 'D1');
        $this->card($user, $board, 'done', 2, 'D2'); // occupies position 2 in the destination

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('sortMode', Task::SORT_DUE)
            ->call('moveTask', $moving->id, 'done', 0)
            ->assertHasNoErrors();

        $moving->refresh();
        $this->assertSame('done', $moving->status);
        $this->assertGreaterThan(2, $moving->position, 'appended past the existing cards, no tie');
        // Destination column still has all three cards at distinct positions.
        $this->assertSame(3, Task::where('board_id', $board->id)->where('status', 'done')->count());
    }

    public function test_a_crafted_negative_move_position_is_clamped(): void
    {
        // #6: $position is client-dispatched. A negative slot must not drive a wide
        // negative-position write or escape the retry handler.
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        $board = $this->boardOwnedBy($user);

        $this->card($user, $board, 'done', 1, 'D1');
        $moving = $this->card($user, $board, 'todo', 1, 'Mover');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $moving->id, status: 'done', position: -1000000)
            ->assertHasNoErrors();

        $moving->refresh();
        $this->assertSame('done', $moving->status);
        $this->assertGreaterThanOrEqual(1, $moving->position, 'position clamped to a sane non-negative slot');
    }
}
