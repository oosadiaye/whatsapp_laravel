<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Livewire\TaskBoard;
use App\Mail\TaskStatusChangedMail;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBoardTest extends TestCase
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
     * A user with NO tasks.* permission at all.
     *
     * NOTE: revokePermissionTo() only strips DIRECTLY-assigned permissions —
     * it cannot remove a grant that came from a role. Negative-permission tests
     * must therefore use a role-less user, not revoke on a role'd one.
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

    public function test_board_requires_tasks_view_permission(): void
    {
        $user = $this->makeUnprivilegedUser();
        $this->actingAs($user);

        $this->makeBoard($user);

        Livewire::test(TaskBoard::class)
            ->assertForbidden();
    }

    public function test_agent_with_tasks_view_can_render_the_board(): void
    {
        $user = $this->makeUser('agent');
        $this->actingAs($user);

        $board = $this->makeBoard($user);
        Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Call the customer back',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertOk()
            ->assertSee('Call the customer back')
            ->assertSee('To Do');
    }

    public function test_creating_a_task_persists_it_without_sending_a_notification(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Draft the invoice')
            ->set('description', 'Net 30 terms')
            ->set('newTaskStatus', 'in_progress')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Draft the invoice')->firstOrFail();

        $this->assertSame('in_progress', $task->status);
        $this->assertSame($user->id, $task->user_id);
        $this->assertSame($board->id, $task->board_id);
        $this->assertSame('Net 30 terms', $task->description);

        // Creation is not a TRANSITION — the creator already knows. Only a
        // card changing column notifies, so this must stay silent.
        Mail::assertNothingSent();
    }

    public function test_creating_a_task_requires_a_title(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', '')
            ->call('createTask')
            ->assertHasErrors(['title']);

        $this->assertSame(0, Task::count());
    }

    public function test_creating_a_task_rejects_an_unknown_status(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Sneaky status')
            ->set('newTaskStatus', 'not-a-real-column')
            ->call('createTask')
            ->assertHasErrors(['newTaskStatus']);
    }

    public function test_moving_a_task_between_columns_updates_it_and_emits(): void
    {
        Event::fake([TaskStatusChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Ship the release',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'done', 0);

        $this->assertSame('done', $task->fresh()->status);

        Event::assertDispatched(
            TaskStatusChanged::class,
            fn (TaskStatusChanged $e) => $e->oldStatus === 'todo' && $e->newStatus === 'done'
        );
    }

    public function test_moving_a_task_rejects_an_unknown_status(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Keep my status',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'hacked-column', 0)
            ->assertStatus(422);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_reordering_inside_the_same_column_does_not_send_email(): void
    {
        // A pure reorder (same status) must not spam the board owner's inbox —
        // only a genuine status transition notifies.
        Mail::fake();

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Same column reorder',
            'status' => 'review',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'review', position: 5);

        // The frontend sends the column's card count as the slot; the controller
        // reserves a free position one past it, so a reorder never lands on a
        // slot already occupied. 5 in, 6 out.
        $this->assertSame(6, $task->fresh()->position);
        Mail::assertNothingSent();
    }

    public function test_status_change_sends_one_email_to_the_board_owner(): void
    {
        Mail::fake();

        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);

        $actor = $this->makeUser();
        $this->actingAs($actor);

        $task = Task::create([
            'user_id' => $actor->id,
            'board_id' => $board->id,
            'title' => 'Refund the duplicate charge',
            'description' => 'Customer was billed twice.',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'done', position: 0);

        // The listener is ShouldQueue and then calls Mail::queue(), giving one
        // queued job per recipient - the same per-recipient granularity as
        // SendCampaignEmail, so one bad address cannot fail the whole batch.
        Mail::assertQueued(TaskStatusChangedMail::class, 1);
        Mail::assertQueued(
            TaskStatusChangedMail::class,
            fn (TaskStatusChangedMail $mail) => $mail->hasTo($owner->email)
                && $mail->oldStatus === 'todo'
                && $mail->newStatus === 'done'
        );
    }

    public function test_agent_without_tasks_edit_cannot_move_cards(): void
    {
        // The agent allowlist grants tasks.view + tasks.create but NOT
        // tasks.edit, so a crafted Livewire payload must be rejected.
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);

        $task = Task::create([
            'user_id' => $agent->id,
            'board_id' => $board->id,
            'title' => 'Not yours to move',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('moveTask', $task->id, 'done', 0)
            ->assertForbidden();

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_agent_can_create_a_task_with_their_granted_permissions(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Chase the signed copy')
            ->set('newTaskStatus', 'todo')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Chase the signed copy']);
    }

    public function test_agent_without_tasks_delete_cannot_delete_cards(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);

        $task = Task::create([
            'user_id' => $agent->id,
            'board_id' => $board->id,
            'title' => 'Keep me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('deleteTask', $task->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted($task);
    }

    public function test_boards_index_page_renders(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->get(route('boards.index'))
            ->assertOk()
            ->assertSee('Support');

        $this->get(route('boards.show', $board))->assertOk();
    }

    public function test_boards_create_route_is_not_shadowed_by_the_wildcard(): void
    {
        // Regression guard: /boards/create must resolve to BoardController@create,
        // not be swallowed by /boards/{board} ("create" as the board id).
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->get(route('boards.create'))->assertOk();

        $this->assertSame(
            'App\Http\Controllers\BoardController@create',
            app('router')->getRoutes()->getByName('boards.create')->getActionName()
        );
    }

    public function test_creating_a_board_persists_and_redirects(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post(route('boards.store'), ['name' => 'Onboarding'])
            ->assertRedirect();

        $this->assertDatabaseHas('boards', ['name' => 'Onboarding', 'user_id' => $user->id]);
    }

    public function test_board_creation_is_rejected_without_permission(): void
    {
        $user = $this->makeUnprivilegedUser();
        $this->actingAs($user);

        $this->post(route('boards.store'), ['name' => 'Sneaky'])->assertForbidden();

        $this->assertDatabaseMissing('boards', ['name' => 'Sneaky']);
    }

    public function test_default_tasks_route_never_writes_on_get(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->assertSame(0, Board::count());

        // A GET must not create. It should bounce to the create form, which
        // this super_admin is allowed to reach.
        $this->get(route('tasks.index'))
            ->assertRedirect(route('boards.create'));

        $this->assertSame(0, Board::count());
    }

    public function test_default_tasks_route_renders_the_first_board(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertViewIs('boards.show', ['board' => $board]);
    }

    public function test_default_tasks_route_sends_view_only_user_to_the_board_list(): void
    {
        // Holds tasks.view but NOT tasks.create — must not be pushed at a
        // create screen they cannot use.
        $user = $this->makeUnprivilegedUser();
        $user->givePermissionTo('tasks.view');
        $this->actingAs($user);

        $this->get(route('tasks.index'))->assertRedirect(route('boards.index'));

        $this->assertSame(0, Board::count());
    }

    public function test_deleted_tasks_are_soft_deleted(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Soft delete me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('deleteTask', $task->id)
            ->assertOk();

        $this->assertSoftDeleted($task);
    }

    public function test_deleting_a_board_soft_deletes_its_tasks(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Do not orphan me',
            'status' => 'todo',
            'position' => 1,
        ]);

        $board->delete();

        // The FK onDelete('cascade') is a HARD-delete rule and would not fire
        // for a soft delete, so Board::booted() cascades it explicitly.
        $this->assertSoftDeleted('boards', ['id' => $board->id]);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_a_status_added_at_runtime_becomes_a_board_column(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        // Columns come from the database, so a new stage needs no deploy, no
        // config edit and no cache clear.
        TaskStatus::create([
            'name' => 'Blocked',
            'slug' => 'blocked',
            'color' => 'red',
            'position' => 2,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSee('Blocked');
    }

    public function test_a_card_can_be_created_in_a_status_added_at_runtime(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        TaskStatus::create([
            'name' => 'Blocked',
            'slug' => 'blocked',
            'color' => 'red',
            'position' => 2,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Waiting on legal')
            ->set('newTaskStatus', 'blocked')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'board_id' => $board->id,
            'title' => 'Waiting on legal',
            'status' => 'blocked',
        ]);
    }

    public function test_a_card_can_be_dropped_into_a_status_added_at_runtime(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        TaskStatus::create([
            'name' => 'Blocked',
            'slug' => 'blocked',
            'color' => 'red',
            'position' => 2,
        ]);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Move me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'blocked', position: 1)
            ->assertOk();

        $this->assertSame('blocked', $task->fresh()->status);
    }

    public function test_a_status_retired_by_an_admin_stops_accepting_drops(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Stale drop',
            'status' => 'todo',
            'position' => 1,
        ]);

        // Delete the column out from under a stale browser tab: the crafted
        // payload must be rejected, not silently persist a dead status.
        TaskStatus::where('slug', 'review')->delete();

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'review', position: 1)
            ->assertStatus(422);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_a_move_is_broadcast_to_the_board_channel(): void
    {
        // Real-time board: other agents watching the same board get the
        // change pushed, so the email listener is not the only consumer.
        Event::fake([TaskStatusChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Push me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'done', position: 0)
            ->assertOk();

        Event::assertDispatched(TaskStatusChanged::class, function (TaskStatusChanged $e) use ($task, $board): bool {
            return $e->broadcastOn()[0]->name === 'private-boards.'.$board->id
                && $e->broadcastAs() === 'task.status.changed'
                && $e->broadcastWith() === [
                    'taskId' => $task->id,
                    'boardId' => $board->id,
                    'oldStatus' => 'todo',
                    'newStatus' => 'done',
                ];
        });
    }

    public function test_a_pure_reorder_is_not_broadcast(): void
    {
        Event::fake([TaskStatusChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Just re-order me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'todo', position: 5)
            ->assertOk();

        // The frontend sends the column's card count as the slot; the controller
        // reserves a free position one past it. 5 in, 6 out.
        $this->assertSame(6, $task->fresh()->position);
        Event::assertNotDispatched(TaskStatusChanged::class);
    }

    public function test_creating_a_card_pushes_the_board_change(): void
    {
        Event::fake([TaskBoardChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', 'Chase the renewal')
            ->set('newTaskStatus', 'todo')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Chase the renewal')->firstOrFail();

        Event::assertDispatched(TaskBoardChanged::class, fn (TaskBoardChanged $e) => $e->broadcastOn()[0]->name === 'private-boards.'.$board->id
            && $e->broadcastAs() === 'task.board.changed'
            && $e->broadcastWith() === [
                'boardId' => $board->id,
                'taskId' => $task->id,
                'reason' => TaskBoardChanged::REASON_CREATED,
            ]);
    }

    public function test_deleting_a_card_pushes_the_board_change(): void
    {
        Event::fake([TaskBoardChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Obsolete',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('deleteTask', $task->id)
            ->assertOk();

        Event::assertDispatched(TaskBoardChanged::class, fn (TaskBoardChanged $e) => $e->broadcastOn()[0]->name === 'private-boards.'.$board->id
            && $e->reason === TaskBoardChanged::REASON_DELETED
            && $e->taskId === $task->id);
    }

    /**
     * A move already broadcasts as .task.status.changed. Emitting the board
     * event too would make every subscriber re-render twice for one drag.
     */
    public function test_a_move_does_not_also_push_a_board_change(): void
    {
        Event::fake([TaskStatusChanged::class, TaskBoardChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $task = Task::create([
            'user_id' => $user->id,
            'board_id' => $board->id,
            'title' => 'Move me',
            'status' => 'todo',
            'position' => 1,
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->dispatch('task-moved', taskId: $task->id, status: 'done', position: 0)
            ->assertOk();

        Event::assertDispatched(TaskStatusChanged::class);
        Event::assertNotDispatched(TaskBoardChanged::class);
    }

    public function test_a_rejected_create_does_not_push_a_board_change(): void
    {
        Event::fake([TaskBoardChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('title', '')
            ->call('createTask')
            ->assertHasErrors(['title']);

        Event::assertNotDispatched(TaskBoardChanged::class);
    }

    public function test_deleting_an_unknown_card_is_a_404_not_a_silent_success(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('deleteTask', 999999)
            ->assertNotFound();
    }
}
