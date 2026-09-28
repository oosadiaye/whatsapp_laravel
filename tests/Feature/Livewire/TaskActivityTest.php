<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Livewire\TaskBoard;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The append-only history behind a card.
 *
 * Two things carry the weight here. First, the write happens on the event, not
 * in the component: that is what makes the log trustworthy for a future report,
 * because any new write path is captured for free. Second, a rejected action
 * must leave no trace - a history full of entries for things that did not happen
 * is worse than no history, because it stops being evidence.
 */
class TaskActivityTest extends TestCase
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

    private function makeTask(Board $board, User $creator, string $status = 'todo'): Task
    {
        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position).
        $position = (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $creator->id,
            'board_id' => $board->id,
            'title' => 'A card',
            'status' => $status,
            'position' => $position,
        ]);
    }

    private function entriesFor(int $taskId): Collection
    {
        return TaskActivity::where('task_id', $taskId)->orderBy('id')->get();
    }

    public function test_creating_a_card_records_an_entry_with_the_actor_and_arrival_column(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('title', 'First card')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'First card')->firstOrFail();
        $entry = $this->entriesFor($task->id)->firstOrFail();

        $this->assertSame(TaskActivity::TYPE_CREATED, $entry->type);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($board->id, $entry->board_id);
        // A creation is the card's arrival in a column, which is the first
        // half of a time-in-column calculation.
        $this->assertSame($task->status, $entry->to_status);
        $this->assertNull($entry->from_status);
    }

    public function test_a_move_records_both_sides_of_the_transition(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->call('moveTask', $task->id, 'in_progress', 0)
            ->assertHasNoErrors();

        $entry = $this->entriesFor($task->id)
            ->where('type', TaskActivity::TYPE_STATUS_CHANGED)
            ->firstOrFail();

        $this->assertSame('todo', $entry->from_status);
        $this->assertSame('in_progress', $entry->to_status);
        $this->assertTrue($entry->isTransition());
    }

    public function test_deleting_a_card_keeps_the_history_of_that_card(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->call('deleteTask', $task->id)
            ->assertHasNoErrors();

        $entry = $this->entriesFor($task->id)
            ->where('type', TaskActivity::TYPE_DELETED)
            ->firstOrFail();

        $this->assertSame($user->id, $entry->user_id);
        // The soft-deleted card still resolves through the relation, so the
        // history can say which card it belongs to.
        $this->assertNotNull($entry->task);
    }

    public function test_an_assignment_change_snapshots_the_people_involved(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);
        $teammate = $this->makeUser();

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->call('toggleAssignee', $teammate->id)
            ->assertHasNoErrors();

        $entry = $this->entriesFor($task->id)
            ->where('type', TaskActivity::TYPE_ASSIGNMENT_CHANGED)
            ->firstOrFail();

        // Snapshotted, because the entry has to still explain itself after the
        // assignees have moved on.
        $this->assertSame([$teammate->id], $entry->metadata['assignee_ids']);
    }

    public function test_a_comment_records_an_entry(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->set('commentBody', 'needs a second look')
            ->call('addComment')
            ->assertHasNoErrors();

        $entry = $this->entriesFor($task->id)
            ->where('type', TaskActivity::TYPE_COMMENTED)
            ->firstOrFail();

        $this->assertSame($user->id, $entry->user_id);
    }

    public function test_a_rejected_action_records_nothing(): void
    {
        // The point of the negative test: a history that records things which
        // did not happen stops being evidence and becomes decoration.
        $viewer = $this->makeUser('agent');
        $board = $this->makeBoard($viewer);
        $task = $this->makeTask($board, $viewer);

        Livewire::actingAs($viewer)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->call('moveTask', $task->id, 'in_progress', 0)
            ->assertForbidden();

        $this->assertDatabaseCount('task_activity', 0);
        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_a_spoofed_move_from_another_board_records_nothing(): void
    {
        $admin = $this->makeUser();
        $mine = $this->makeBoard($admin);
        $theirs = $this->makeBoard($admin);
        $foreignTask = $this->makeTask($theirs, $admin);

        Livewire::actingAs($admin)
            ->test(TaskBoard::class, ['boardId' => $mine->id])
            ->call('moveTask', $foreignTask->id, 'in_progress', 0)
            // 404, not 403: the card exists, just not here. Answering 403
            // would confirm a card the user cannot currently see exists
            // somewhere else in the system.
            ->assertNotFound();

        // Defence in depth: the activity table records real events, so a payload
        // that never produced a real move must not produce a row.
        $this->assertDatabaseCount('task_activity', 0);
        $this->assertSame('todo', $foreignTask->fresh()->status);
    }

    public function test_the_history_panel_renders_the_latest_entries_newest_first(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        foreach (range(1, 3) as $i) {
            TaskActivity::factory()
                ->forTask($task->id, $board->id)
                ->by($user->id)
                ->create(['created_at' => now()->addMinutes($i)]);
        }

        $entries = Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->viewData('openTaskActivity');

        $this->assertCount(3, $entries);
        // Newest first, so the top of the list is the thing that just happened.
        $this->assertTrue(
            $entries->first()->id > $entries->last()->id,
            'history should be ordered newest first',
        );
    }

    public function test_the_history_panel_is_capped_but_the_log_keeps_everything(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        TaskActivity::factory()
            ->count(25)
            ->forTask($task->id, $board->id)
            ->create();

        $shown = Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->viewData('openTaskActivity');

        // 25 written, 20 shown. The panel is a "what just happened" glance, not
        // an archive browser - but the log keeps all of it.
        $this->assertCount(20, $shown);
        $this->assertSame(25, TaskActivity::where('task_id', $task->id)->count());
    }

    public function test_history_does_not_leak_across_cards(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $mine = $this->makeTask($board, $user);
        $other = $this->makeTask($board, $user);

        TaskActivity::factory()->forTask($other->id, $board->id)->create();

        $shown = Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $mine->id)
            ->viewData('openTaskActivity');

        $this->assertCount(0, $shown);
    }

    public function test_the_table_has_no_updated_at_column(): void
    {
        // A log that could be edited is not a log. The column's absence is the
        // guarantee, so it is asserted rather than trusted to a comment.
        $this->assertFalse(
            DB::getSchemaBuilder()->hasColumn('task_activity', 'updated_at'),
            'task_activity must not carry an updated_at column',
        );
    }

    public function test_a_row_with_no_actor_still_renders_rather_than_throwing(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        // A console command or queued job acts with no signed-in user. The log
        // must still record it rather than being unrecordable.
        TaskActivity::factory()
            ->forTask($task->id, $board->id)
            ->by(null)
            ->create(['type' => TaskActivity::TYPE_UPDATED]);

        // The panel falls back to "Someone" for a row with no signed-in actor,
        // so a system action reads as an action rather than a broken record.
        $component = Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id);

        $entry = $component->viewData('openTaskActivity')->first();

        $this->assertNull($entry->user_id);
        $this->assertSame('edited the description', $entry->summary());
        $component->assertSee('Someone');
    }

    public function test_deleting_a_comment_records_an_entry_and_removes_the_comment(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'message' => 'Needs a second look',
        ]);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->call('deleteComment', $comment->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
        // A retraction is not an arrival, in the log or in anyone's inbox.
        $this->assertSame(
            TaskActivity::TYPE_COMMENT_REMOVED,
            $this->entriesFor($task->id)->firstOrFail()->type,
        );
    }

    public function test_a_triage_edit_is_not_logged_or_mailed_as_a_description_edit(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        Livewire::actingAs($user)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->call('saveTriage')
            ->assertHasNoErrors();

        $entry = $this->entriesFor($task->id)->firstOrFail();

        $this->assertSame(TaskActivity::TYPE_TRIAGE_UPDATED, $entry->type);
        $this->assertStringNotContainsString('description', $entry->summary());
    }

    public function test_every_emailable_reason_is_recorded_in_the_log(): void
    {
        // The recorder drops reasons it does not recognise rather than filing
        // them under a wrong heading. That is the right behaviour at runtime and
        // a trap at authoring time: adding a reason to TaskBoardChanged and
        // forgetting it here would leave a silent gap in the history. So the
        // vocabulary is pinned instead of left to review.
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        foreach (TaskBoardChanged::EMAILABLE_REASONS as $reason) {
            event(new TaskBoardChanged(
                $board->id,
                $reason,
                $task->id,
                $user->id,
            ));
        }

        $recorded = TaskActivity::where('task_id', $task->id)
            ->pluck('type')
            ->all();

        $this->assertCount(
            count(TaskBoardChanged::EMAILABLE_REASONS),
            $recorded,
            'every emailable reason must produce exactly one log entry',
        );
        $this->assertNotContains(null, $recorded, 'no emailable reason may be dropped');
    }

    public function test_a_transition_summary_uses_the_runtime_column_names(): void
    {
        TaskStatus::query()->where('slug', 'todo')->update(['name' => 'Backlog']);

        $entry = TaskActivity::factory()
            ->forTask(1, 1)
            ->transition('todo', 'in_progress')
            ->create();

        // The panel must not show a raw slug just because a column was renamed
        // after the fact.
        $this->assertStringContainsString('Backlog', $entry->summary());
        $this->assertStringNotContainsString('todo', $entry->summary());
    }

    public function test_a_console_drive_change_is_recorded_with_no_actor(): void
    {
        $user = $this->makeUser();
        $board = $this->makeBoard($user);
        $task = $this->makeTask($board, $user);

        // A service or command that acts with no signed-in user still produces
        // an event, so the log is still written. The reader has to cope with
        // "Someone" rather than the listener refusing to record it.
        event(new TaskStatusChanged(
            $task,
            'todo',
            'in_progress',
            actorId: null,
        ));

        $entry = $this->entriesFor($task->id)->firstOrFail();

        $this->assertNull($entry->user_id);
        $this->assertSame(TaskActivity::TYPE_STATUS_CHANGED, $entry->type);
    }
}
