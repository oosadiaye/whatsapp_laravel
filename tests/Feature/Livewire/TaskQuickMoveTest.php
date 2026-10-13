<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Events\TaskStatusChanged;
use App\Livewire\TaskBoard;
use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One-tap card movement: "move to next column" and "mark done".
 *
 * Native HTML5 drag-and-drop does not fire on touch screens at all, and the board's
 * drag script was additionally blocked by the site CSP (an inline <script> with no
 * nonce), so dragging did nothing in production. These actions give every card a
 * reliable way to advance that does not depend on a drag gesture, and they delegate
 * to moveTask() so permission, status validation, collision-safe positions,
 * notifications and the WIP notice behave exactly like a drag.
 */
class TaskQuickMoveTest extends TestCase
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
        return Board::create(['user_id' => $owner->id, 'name' => 'Support', 'slug' => 'support']);
    }

    private function makeTask(Board $board, User $owner, string $status, int $position = 1, string $title = 'Card'): Task
    {
        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => $title,
            'status' => $status,
            'position' => $position,
        ]);
    }

    public function test_advance_moves_a_card_to_the_next_column(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'todo');

        Livewire::test(TaskBoard::class, ['board' => $board])->call('advanceTask', $task->id);

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_advance_walks_a_card_through_every_column_in_order(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'todo');
        $component = Livewire::test(TaskBoard::class, ['board' => $board]);

        $seen = [];
        foreach (range(1, 3) as $_) {
            $component->call('advanceTask', $task->id);
            $seen[] = $task->fresh()->status;
        }

        $this->assertSame(['in_progress', 'review', 'done'], $seen);
    }

    public function test_advance_lands_at_the_end_of_a_column_that_already_has_cards(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $existingA = $this->makeTask($board, $user, 'in_progress', 1, 'A');
        $existingB = $this->makeTask($board, $user, 'in_progress', 2, 'B');
        $moving = $this->makeTask($board, $user, 'todo', 1, 'Moving');

        Livewire::test(TaskBoard::class, ['board' => $board])->call('advanceTask', $moving->id);

        $moved = $moving->fresh();
        $this->assertSame('in_progress', $moved->status);
        $this->assertGreaterThan($existingB->fresh()->position, $moved->position, 'must land after the existing cards');
        $this->assertSame(1, $existingA->fresh()->position, 'existing cards must not be shuffled');
    }

    public function test_advance_from_the_last_column_does_nothing(): void
    {
        Event::fake([TaskStatusChanged::class]);
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'done');

        Livewire::test(TaskBoard::class, ['board' => $board])->call('advanceTask', $task->id);

        $this->assertSame('done', $task->fresh()->status);
        Event::assertNotDispatched(TaskStatusChanged::class);
    }

    public function test_complete_jumps_straight_to_the_done_column_from_anywhere(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $fromTodo = $this->makeTask($board, $user, 'todo', 1, 'From todo');
        $fromReview = $this->makeTask($board, $user, 'review', 1, 'From review');
        $component = Livewire::test(TaskBoard::class, ['board' => $board]);

        $component->call('completeTask', $fromTodo->id)->call('completeTask', $fromReview->id);

        $this->assertSame('done', $fromTodo->fresh()->status);
        $this->assertSame('done', $fromReview->fresh()->status);
        $this->assertNotSame($fromTodo->fresh()->position, $fromReview->fresh()->position, 'positions are unique per column');
    }

    public function test_complete_on_an_already_done_card_does_nothing(): void
    {
        Event::fake([TaskStatusChanged::class]);
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'done');

        Livewire::test(TaskBoard::class, ['board' => $board])->call('completeTask', $task->id);

        $this->assertSame('done', $task->fresh()->status);
        Event::assertNotDispatched(TaskStatusChanged::class);
    }

    public function test_a_quick_move_notifies_like_a_drag_does(): void
    {
        Event::fake([TaskStatusChanged::class]);
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'todo');

        Livewire::test(TaskBoard::class, ['board' => $board])->call('completeTask', $task->id);

        Event::assertDispatched(
            TaskStatusChanged::class,
            fn (TaskStatusChanged $e): bool => $e->task->id === $task->id
        );
    }

    public function test_an_agent_without_tasks_edit_cannot_use_the_quick_moves(): void
    {
        // The agent role has tasks.view + tasks.create but NOT tasks.edit.
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);
        $task = $this->makeTask($board, $agent, 'todo');

        // A 403 leaves the Livewire test instance without a snapshot, so each
        // forbidden call needs its own component.
        Livewire::test(TaskBoard::class, ['board' => $board])->call('advanceTask', $task->id)->assertForbidden();
        Livewire::test(TaskBoard::class, ['board' => $board])->call('completeTask', $task->id)->assertForbidden();

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_a_card_on_another_board_cannot_be_moved(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $mine = $this->makeBoard($user);
        $other = Board::create(['user_id' => $user->id, 'name' => 'Other', 'slug' => 'other']);
        $foreign = $this->makeTask($other, $user, 'todo');

        Livewire::test(TaskBoard::class, ['board' => $mine])
            ->call('advanceTask', $foreign->id)
            ->assertNotFound();

        $this->assertSame('todo', $foreign->fresh()->status);
    }

    public function test_cards_render_the_next_and_done_buttons_only_where_they_apply(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $open = $this->makeTask($board, $user, 'todo', 1, 'Open card');
        $finished = $this->makeTask($board, $user, 'done', 1, 'Finished card');

        $html = Livewire::test(TaskBoard::class, ['board' => $board])->html();

        $this->assertStringContainsString('wire:click="advanceTask('.$open->id.')"', $html);
        $this->assertStringContainsString('wire:click="completeTask('.$open->id.')"', $html);
        $this->assertStringNotContainsString('wire:click="advanceTask('.$finished->id.')"', $html);
        $this->assertStringNotContainsString('wire:click="completeTask('.$finished->id.')"', $html);
    }

    public function test_the_board_drag_script_carries_the_csp_nonce(): void
    {
        // The board's inline drag-and-drop <script> had no nonce, so the production
        // CSP (script-src 'self' 'nonce-...') refused to run it and dragging did
        // nothing. Every other inline script in the app carries one.
        $user = $this->makeUser();
        $board = $this->makeBoard($user);

        $html = $this->actingAs($user)->get(route('boards.show', $board))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<script nonce="[^"]+">\s*// Vanilla HTML5 drag-and-drop~',
            $html,
            'the board drag-and-drop script must carry the CSP nonce or the browser blocks it'
        );
    }
}
