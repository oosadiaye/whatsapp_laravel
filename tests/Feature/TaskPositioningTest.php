<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\TaskBoard;
use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Card ordering (TASK-BOARD.md 3.6).
 *
 * The load-bearing guarantee: two cards in the same column must never share a
 * position. The old code stored the dropped column's *count* as the position,
 * so a moved card landed on the integer already held by the card at that slot —
 * a silent tie that ordered by id and let a drag go somewhere other than where
 * it was dropped. The controller now reserves a free slot (pushing the suffix up
 * one) and the schema backs that with a unique (board_id, status, position)
 * index, so a concurrent drag that read a stale slot is rejected and retried
 * rather than tying. These tests assert the invariant, not the internal maths.
 */
class TaskPositioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('manager');

        return $u;
    }

    private function board(User $owner): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
    }

    private function task(User $owner, Board $board, string $status, int $position): Task
    {
        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => 'Card '.$position,
            'status' => $status,
            'position' => $position,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function columnPositions(Board $board, string $status): array
    {
        return Task::where('board_id', $board->id)
            ->where('status', $status)
            ->pluck('position')
            ->all();
    }

    private function assertNoTies(Board $board): void
    {
        foreach (['todo', 'in_progress', 'done', 'review'] as $status) {
            $positions = $this->columnPositions($board, $status);
            $this->assertSame(
                count($positions),
                count(array_unique($positions)),
                "column {$status} has duplicate positions: ".implode(',', $positions)
            );
        }
    }

    public function test_moving_cards_never_produces_a_shared_position(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        $board = $this->board($owner);

        $a = $this->task($owner, $board, 'todo', 1);
        $b = $this->task($owner, $board, 'todo', 2);
        $c = $this->task($owner, $board, 'todo', 3);

        Livewire::test(TaskBoard::class, ['board' => $board])
            // c (todo, pos 3) -> in_progress: appended to the empty column.
            ->call('moveTask', $c->id, 'in_progress', 0)
            // a (todo, pos 1) -> in_progress: reserved a free slot, not pos 0.
            ->call('moveTask', $a->id, 'in_progress', 1)
            // b reordered to the front of todo.
            ->call('moveTask', $b->id, 'todo', 0);

        $this->assertNoTies($board);

        // Each column still has exactly the cards it should, with no shared slot.
        $this->assertSame([1, 2], $this->columnPositions($board, 'in_progress'));
        $this->assertSame([1], $this->columnPositions($board, 'todo'));
    }

    public function test_append_semantics_drop_at_the_end_not_on_an_occupied_slot(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        $board = $this->board($owner);

        $a = $this->task($owner, $board, 'todo', 1);
        $b = $this->task($owner, $board, 'todo', 2);

        // The frontend sends the column's card count (2) as the slot.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $a->id, 'todo', 2);

        // Appended after b: a reserved slot 3, distinct from b's 2.
        $this->assertNoTies($board);
        $this->assertSame(3, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_the_database_refuses_a_duplicate_position_in_a_column(): void
    {
        $owner = $this->user();
        $board = $this->board($owner);

        $this->task($owner, $board, 'todo', 4);

        // The unique (board_id, status, position) index is the backstop: a race
        // the controller cannot see must still be impossible, not a silent tie.
        $this->expectException(QueryException::class);

        Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => 'Colliding',
            'status' => 'todo',
            'position' => 4,
        ]);
    }
}
