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
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Triage fields: due date, priority, estimate, and the overdue view.
 *
 * The interesting behaviour is not that the fields save - it is the two rules
 * that are easy to get subtly wrong: a card sitting in a *completed* column must
 * never read as overdue, and sorting must be a view that cannot rewrite the
 * stored manual order.
 */
class TaskTriageTest extends TestCase
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
            'slug' => 'support-'.uniqid(),
            'color' => '#6366f1',
        ]);
    }

    private function makeTask(Board $board, User $creator, string $status = 'todo', int $position = 1): Task
    {
        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position). max+1 keeps creation order, which
        // is what the caller's sequential positions implied anyway.
        $position = (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $creator->id,
            'board_id' => $board->id,
            'title' => 'Card '.$position,
            'status' => $status,
            'position' => $position,
        ]);
    }

    public function test_a_new_card_defaults_to_normal_priority_with_no_deadline(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'No deadline')
            ->call('createTask');

        $task = Task::where('title', 'No deadline')->firstOrFail();

        $this->assertSame(Task::PRIORITY_NORMAL, $task->priority);
        $this->assertNull($task->due_at);
        $this->assertNull($task->estimate_minutes);
    }

    public function test_triage_fields_save_from_the_drawer(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailDueAt', '2026-10-01T14:30')
            ->set('detailPriority', Task::PRIORITY_URGENT)
            ->set('detailEstimate', '90')
            ->call('saveTriage')
            ->assertHasNoErrors();

        $task->refresh();

        $this->assertSame(Task::PRIORITY_URGENT, $task->priority);
        $this->assertSame('2026-10-01 14:30', $task->due_at->format('Y-m-d H:i'));
        $this->assertSame(90, $task->estimate_minutes);
    }

    public function test_clearing_the_fields_stores_null_not_the_epoch(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        $task->update(['due_at' => now()->addWeek(), 'estimate_minutes' => 30]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailDueAt', '')
            ->set('detailEstimate', '')
            ->call('saveTriage')
            ->assertHasNoErrors();

        $task->refresh();

        // An empty datetime-local posts '', and 0 is a real estimate - only
        // '' and null clear it.
        $this->assertNull($task->due_at);
        $this->assertNull($task->estimate_minutes);
    }

    public function test_opening_a_card_loads_its_triage_values_into_the_form(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        $task->update([
            'due_at' => Carbon::parse('2026-10-01 14:30'),
            'priority' => Task::PRIORITY_HIGH,
            'estimate_minutes' => 45,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->assertSet('detailDueAt', '2026-10-01T14:30')
            ->assertSet('detailPriority', Task::PRIORITY_HIGH)
            ->assertSet('detailEstimate', '45');
    }

    public function test_an_out_of_range_priority_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailPriority', 99)
            ->call('saveTriage')
            ->assertHasErrors('detailPriority');

        $this->assertSame(Task::PRIORITY_NORMAL, $task->fresh()->priority);
    }

    public function test_a_malformed_due_date_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailDueAt', 'next tuesday-ish')
            ->call('saveTriage')
            ->assertHasErrors('detailDueAt');
    }

    public function test_an_agent_cannot_edit_triage_fields(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);
        $task = $this->makeTask($board, $agent);

        // Agents hold tasks.view + tasks.create, so a crafted payload must not
        // be able to set a deadline on a shared card.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('saveTriage')
            ->assertForbidden();
    }

    public function test_saving_triage_requires_an_open_card(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('saveTriage')
            ->assertStatus(422);
    }

    public function test_an_overdue_card_is_flagged_and_a_future_one_is_not(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $late = $this->makeTask($board, $user, 'todo', 1);
        $late->update(['due_at' => now()->subDay()]);

        $future = $this->makeTask($board, $user, 'todo', 2);
        $future->update(['due_at' => now()->addDay()]);

        $undated = $this->makeTask($board, $user, 'todo', 3);

        $rendered = Livewire::test(TaskBoard::class, ['board' => $board]);

        $this->assertTrue($late->isOverdue(['done']));
        $this->assertFalse($future->isOverdue(['done']));
        $this->assertFalse($undated->isOverdue(['done']));

        $rendered->assertSee('Overdue');
        $rendered->assertSee('Due '.Carbon::now()->addDay()->format('j M'));
    }

    public function test_a_past_due_date_in_a_completed_column_is_not_overdue(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'done');

        $task->update(['title' => 'Finished last month', 'due_at' => now()->subMonth()]);

        $this->assertFalse(
            $task->isOverdue(TaskStatus::completedSlugs()),
            'finished work must never render as at risk, however old its deadline'
        );

        // Asserted on the card title, not the word "Overdue" - the toolbar
        // always renders an "Overdue only" button.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('toggleOnlyOverdue')
            ->assertDontSee('Finished last month');
    }

    public function test_the_overdue_filter_hides_unfinished_cards_that_are_not_late(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $late = $this->makeTask($board, $user, 'todo', 1);
        $late->update(['title' => 'Late card', 'due_at' => now()->subDay()]);

        $fine = $this->makeTask($board, $user, 'todo', 2);
        $fine->update(['title' => 'Fine card', 'due_at' => now()->addWeek()]);

        $undated = $this->makeTask($board, $user, 'todo', 3);
        $undated->update(['title' => 'Undated card']);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('toggleOnlyOverdue')
            ->assertSee('Late card')
            ->assertDontSee('Fine card')
            ->assertDontSee('Undated card');
    }

    public function test_sorting_by_due_date_puts_undated_cards_last(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $soon = $this->makeTask($board, $user, 'todo', 1);
        $soon->update(['title' => 'Soon', 'due_at' => now()->addDay()]);

        $undated = $this->makeTask($board, $user, 'todo', 2);
        $undated->update(['title' => 'Undated']);

        $late = $this->makeTask($board, $user, 'todo', 3);
        $late->update(['title' => 'Late', 'due_at' => now()->subDay()]);

        $order = Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('sortMode', Task::SORT_DUE)
            ->instance()
            ->render()
            ->getData()['grouped']['todo']
            ->pluck('title')
            ->all();

        $this->assertSame(['Late', 'Soon', 'Undated'], $order);
    }

    public function test_sorting_by_priority_is_highest_first(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $low = $this->makeTask($board, $user, 'todo', 1);
        $low->update(['title' => 'Low', 'priority' => Task::PRIORITY_LOW]);

        $urgent = $this->makeTask($board, $user, 'todo', 2);
        $urgent->update(['title' => 'Urgent', 'priority' => Task::PRIORITY_URGENT]);

        $normal = $this->makeTask($board, $user, 'todo', 3);
        $normal->update(['title' => 'Normal', 'priority' => Task::PRIORITY_NORMAL]);

        $order = Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('sortMode', Task::SORT_PRIORITY)
            ->instance()
            ->render()
            ->getData()['grouped']['todo']
            ->pluck('title')
            ->all();

        $this->assertSame(['Urgent', 'Normal', 'Low'], $order);
    }

    public function test_dragging_in_a_sorted_view_moves_the_column_but_not_the_order(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'todo', 1);
        $originalPosition = $task->position;

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('sortMode', Task::SORT_DUE)
            ->dispatch('task-moved', taskId: $task->id, status: 'done', position: 99);

        $task->refresh();

        // The column change is triage and is honoured...
        $this->assertSame('done', $task->status);
        // ...but the drop index in a sorted view is meaningless, so honouring
        // it would silently scramble the manual arrangement the team built.
        $this->assertSame($originalPosition, $task->position);
    }

    public function test_dragging_in_manual_view_does_write_the_order(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user, 'todo', 1);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'todo', position: 7);

        // The frontend sends the column's card count as the slot; the controller
        // reserves a free position one past it, so 7 in becomes 8 out.
        $this->assertSame(8, $task->fresh()->position);
    }

    public function test_an_unknown_sort_mode_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        // The mode decides the ORDER BY applied to every render, so an
        // unrecognised value must be refused rather than falling through to a
        // match arm. State is not asserted here: an aborted Livewire call drops
        // the component instance, so there is no post-abort value to read.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('setSortMode', 'whatever-the-browser-says')
            ->assertStatus(422);

        // The default is still manual on a fresh render.
        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSet('sortMode', Task::SORT_MANUAL);
    }

    public function test_sort_mode_can_be_set_legitimately(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('setSortMode', Task::SORT_PRIORITY)
            ->assertHasNoErrors()
            ->assertSet('sortMode', Task::SORT_PRIORITY);
    }

    public function test_a_viewer_can_change_the_view_filters_without_editing_cards(): void
    {
        $viewer = $this->makeUser('agent');
        $this->actingAs($viewer);
        $board = $this->makeBoard($viewer);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('setSortMode', Task::SORT_DUE)
            ->assertHasNoErrors()
            ->call('toggleOnlyOverdue')
            ->assertHasNoErrors()
            ->call('toggleOnlyMine')
            ->assertHasNoErrors();
    }

    public function test_priority_label_tolerates_an_unknown_stored_value(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        // A value a hand-edited database could hold must not render blank.
        $task->forceFill(['priority' => 42])->save();

        $this->assertSame('Normal', $task->fresh()->priorityLabel());
    }
}
