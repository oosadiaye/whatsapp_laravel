<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Events\TaskBoardChanged;
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
 * The card drawer: description, assignees, watchers and comments.
 *
 * These actions are driven by state the browser holds ($openTaskId,
 * $detailDescription, $commentBody), so the risk is a crafted payload acting
 * outside the board on screen rather than a broken button. Each test below pins
 * one of those.
 */
class TaskCardDetailTest extends TestCase
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
     * A user with no tasks.* permission at all. revokePermissionTo() only
     * strips direct grants, so a role-less user is the only way to build one.
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
            'slug' => 'support-'.$owner->id,
        ]);
    }

    private function makeTask(User $owner, Board $board, array $overrides = []): Task
    {
        // A unique position within the column unless the caller overrides one.
        // The schema forbids two cards sharing a (board, status, position).
        $status = $overrides['status'] ?? 'todo';
        $position = $overrides['position']
            ?? (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1;

        return Task::create([
            'user_id' => $owner->id,
            'board_id' => $board->id,
            'title' => 'Chase the renewal',
            'status' => $status,
            'position' => $position,
            ...$overrides,
        ]);
    }

    public function test_opening_a_card_shows_its_detail(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board, ['description' => 'Signed copy pending']);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->assertSet('openTaskId', $task->id)
            ->assertSet('detailDescription', 'Signed copy pending')
            ->assertSee('Signed copy pending');
    }

    public function test_a_card_can_be_assigned_and_unassigned(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);
        $teammate = $this->makeUser('agent');

        $component = Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $teammate->id);

        $this->assertTrue($task->assignees()->whereKey($teammate->id)->exists());

        $component->call('toggleAssignee', $teammate->id);

        $this->assertFalse($task->assignees()->whereKey($teammate->id)->exists());
    }

    /**
     * Assignment needs tasks.create, not tasks.edit. An agent can raise a card
     * and must therefore be able to hand it to someone.
     */
    public function test_an_agent_may_assign_a_card(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);
        $task = $this->makeTask($agent, $board);
        $teammate = $this->makeUser('agent');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $teammate->id)
            ->assertOk();

        $this->assertTrue($task->assignees()->whereKey($teammate->id)->exists());
    }

    public function test_a_user_without_tasks_create_cannot_assign(): void
    {
        $viewer = $this->makeUnprivilegedUser();
        $viewer->givePermissionTo('tasks.view');

        $this->actingAs($viewer);
        $board = $this->makeBoard($viewer);
        $task = $this->makeTask($viewer, $board);
        $teammate = $this->makeUser('agent');

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $teammate->id)
            ->assertForbidden();

        $this->assertFalse($task->assignees()->exists());
    }

    public function test_a_deactivated_user_cannot_be_assigned(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        $gone = $this->makeUser('agent');
        $gone->update(['is_active' => false]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $gone->id)
            ->assertNotFound();

        $this->assertFalse($task->assignees()->exists());
    }

    public function test_watching_is_self_service_for_any_viewer(): void
    {
        $viewer = $this->makeUnprivilegedUser();
        $viewer->givePermissionTo('tasks.view');

        $this->actingAs($viewer);
        $board = $this->makeBoard($viewer);
        $task = $this->makeTask($viewer, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleWatcher', $viewer->id)
            ->assertOk();

        $this->assertTrue($task->watchers()->whereKey($viewer->id)->exists());
    }

    public function test_watching_someone_else_is_refused(): void
    {
        $viewer = $this->makeUnprivilegedUser();
        $viewer->givePermissionTo('tasks.view');

        $other = $this->makeUser('agent');

        $this->actingAs($viewer);
        $board = $this->makeBoard($viewer);
        $task = $this->makeTask($viewer, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleWatcher', $other->id)
            ->assertForbidden();

        $this->assertFalse($task->watchers()->whereKey($other->id)->exists());
    }

    public function test_saving_a_description_needs_tasks_edit(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);
        $task = $this->makeTask($agent, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailDescription', 'Agent tries to rewrite this')
            ->call('saveDescription')
            ->assertForbidden();

        $this->assertNull($task->fresh()->description);
    }

    public function test_a_manager_can_save_a_description(): void
    {
        $manager = $this->makeUser('manager');
        $this->actingAs($manager);
        $board = $this->makeBoard($manager);
        $task = $this->makeTask($manager, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('detailDescription', 'Net 30 terms confirmed')
            ->call('saveDescription')
            ->assertHasNoErrors();

        $this->assertSame('Net 30 terms confirmed', $task->fresh()->description);
    }

    public function test_a_viewer_can_post_a_comment(): void
    {
        $viewer = $this->makeUnprivilegedUser();
        $viewer->givePermissionTo('tasks.view');

        $this->actingAs($viewer);
        $board = $this->makeBoard($viewer);
        $task = $this->makeTask($viewer, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('commentBody', 'Customer called twice about this.')
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSet('commentBody', '');

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $viewer->id,
            'message' => 'Customer called twice about this.',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->set('commentBody', '')
            ->call('addComment')
            ->assertHasErrors(['commentBody']);

        $this->assertDatabaseCount('task_comments', 0);
    }

    public function test_an_author_can_delete_their_own_comment(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAs($agent);
        $board = $this->makeBoard($agent);
        $task = $this->makeTask($agent, $board);

        $comment = $task->comments()->create([
            'user_id' => $agent->id,
            'message' => 'Never mind, resolved.',
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('deleteComment', $comment->id)
            ->assertOk();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_a_different_viewer_cannot_delete_someone_elses_comment(): void
    {
        $author = $this->makeUser('agent');
        $other = $this->makeUser('agent');

        $this->actingAs($other);
        $board = $this->makeBoard($author);
        $task = $this->makeTask($author, $board);

        $comment = $task->comments()->create([
            'user_id' => $author->id,
            'message' => 'Not yours to remove.',
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('deleteComment', $comment->id)
            ->assertForbidden();

        $this->assertDatabaseHas('task_comments', ['id' => $comment->id]);
    }

    public function test_someone_with_tasks_delete_can_remove_any_comment(): void
    {
        $author = $this->makeUser('agent');
        $manager = $this->makeUser('manager');

        $this->actingAs($manager);
        $board = $this->makeBoard($author);
        $task = $this->makeTask($author, $board);

        $comment = $task->comments()->create([
            'user_id' => $author->id,
            'message' => 'Off topic.',
        ]);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('deleteComment', $comment->id)
            ->assertOk();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    /**
     * The drawer is addressed by a client-supplied task id, so it must not act
     * on a card from a board the component is not even rendering.
     */
    public function test_the_drawer_refuses_a_card_from_another_board(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $board = $this->makeBoard($user);
        $otherBoard = Board::create([
            'user_id' => $user->id,
            'name' => 'Billing',
            'slug' => 'billing-'.$user->id,
        ]);

        $task = $this->makeTask($user, $board);
        $foreign = $this->makeTask($user, $otherBoard, ['title' => 'Different board']);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->set('openTaskId', $foreign->id)
            ->call('toggleAssignee', $user->id)
            ->assertNotFound();

        $this->assertFalse($foreign->assignees()->exists());
    }

    public function test_drawer_actions_require_an_open_card(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('addComment')
            ->assertStatus(422);
    }

    public function test_opening_a_card_while_the_drawer_is_closed_closes_it(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->assertSet('openTaskId', $task->id)
            ->call('closeTask')
            ->assertSet('openTaskId', null)
            ->assertSet('commentBody', '');
    }

    public function test_deleting_the_open_card_closes_the_drawer(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('deleteTask', $task->id)
            ->assertSet('openTaskId', null);
    }

    public function test_only_mine_filters_to_cards_assigned_to_the_viewer(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);

        $mine = $this->makeTask($user, $board, ['title' => 'Mine', 'position' => 1]);
        $theirs = $this->makeTask($user, $board, ['title' => 'Theirs', 'position' => 2]);

        $mine->assignees()->attach($user->id);
        $theirs->assignees()->attach($this->makeUser('agent')->id);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSee('Mine')
            ->assertSee('Theirs')
            ->call('toggleOnlyMine')
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_assigning_and_commenting_broadcast_to_the_board(): void
    {
        Event::fake([TaskBoardChanged::class]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $user->id)
            ->set('commentBody', 'On it')
            ->call('addComment')
            ->assertHasNoErrors();

        Event::assertDispatched(TaskBoardChanged::class,
            fn (TaskBoardChanged $e) => $e->reason === TaskBoardChanged::REASON_ASSIGNED
                && $e->taskId === $task->id
                && $e->boardId === $board->id);

        Event::assertDispatched(TaskBoardChanged::class,
            fn (TaskBoardChanged $e) => $e->reason === TaskBoardChanged::REASON_COMMENTED
                && $e->taskId === $task->id);
    }

    /**
     * Note: there is deliberately no "the drawer requires tasks.view" test
     * here. A user without tasks.view cannot mount the board at all - render()
     * aborts with 403 - so Livewire never produces a snapshot to act on, and
     * the assertion is unreachable through this harness. The mount-level guard
     * is covered by TaskBoardTest::test_board_requires_tasks_view_permission,
     * and every drawer action re-checks independently below.
     */
    public function test_assignee_badges_show_initials_not_the_first_two_letters(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        $assignee = User::factory()->create(['is_active' => true, 'name' => 'John Smith']);
        $task->assignees()->attach($assignee->id);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSee('JS');
    }

    public function test_a_single_word_name_yields_one_initial(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $board = $this->makeBoard($user);
        $task = $this->makeTask($user, $board);

        $assignee = User::factory()->create(['is_active' => true, 'name' => 'Cher']);
        $task->assignees()->attach($assignee->id);

        Livewire::test(TaskBoard::class, ['board' => $board])
            ->assertSee('C');
    }
}
