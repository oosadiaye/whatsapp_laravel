<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\TaskBoardChanged;
use App\Livewire\TaskBoard;
use App\Mail\TaskActivityMail;
use App\Mail\TaskAssignedMail;
use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "User A raises a task for user B": assign while creating it, B is emailed
 * straight away, and the card shows up as B's own across every board.
 */
final class TaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function makeUser(string $role = 'manager', string $level = User::TASK_NOTIFICATIONS_TRANSITIONS, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active, 'task_notifications' => $level]);
        $user->assignRole($role);

        return $user;
    }

    private function makeBoard(User $owner, string $name = 'Support', bool $archived = false): Board
    {
        return Board::create([
            'user_id' => $owner->id,
            'name' => $name,
            'slug' => 'b-'.uniqid(),
            'archived_at' => $archived ? now() : null,
        ]);
    }

    private function makeTask(Board $board, User $creator, string $status = 'todo', string $title = 'Refund the duplicate charge', ?\DateTimeInterface $dueAt = null): Task
    {
        return Task::create([
            'user_id' => $creator->id,
            'board_id' => $board->id,
            'title' => $title,
            'status' => $status,
            'due_at' => $dueAt,
            'position' => (int) Task::where('board_id', $board->id)->where('status', $status)->max('position') + 1,
        ]);
    }

    private function create(User $actor, Board $board, array $assigneeIds, string $title = 'Call the supplier'): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($actor)
            ->test(TaskBoard::class, ['board' => $board])
            ->set('title', $title)
            ->set('newTaskStatus', 'todo')
            ->set('assigneeIds', $assigneeIds)
            ->call('createTask');
    }

    // ── Assigning while creating ────────────────────────────────────────────

    public function test_a_task_can_be_assigned_to_someone_while_it_is_created(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent');

        $this->create($a, $this->makeBoard($a), [$b->id])->assertHasNoErrors();

        $task = Task::where('title', 'Call the supplier')->firstOrFail();
        $this->assertSame([$b->id], $task->assignees()->pluck('users.id')->all());
    }

    public function test_the_form_resets_so_the_next_task_does_not_inherit_the_assignee(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent');

        $this->create($a, $this->makeBoard($a), [$b->id])->assertSet('assigneeIds', []);
    }

    public function test_a_task_without_assignees_still_works(): void
    {
        Mail::fake();
        $a = $this->makeUser();

        $this->create($a, $this->makeBoard($a), [])->assertHasNoErrors();

        $this->assertCount(0, Task::where('title', 'Call the supplier')->firstOrFail()->assignees);
        Mail::assertNothingQueued();
    }

    public function test_an_unknown_or_deactivated_user_cannot_be_assigned(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $gone = $this->makeUser('agent', active: false);

        $this->create($a, $this->makeBoard($a), [$gone->id])->assertHasErrors('assigneeIds.0');
        $this->create($a, $this->makeBoard($a, 'Other'), [999999])->assertHasErrors('assigneeIds.0');

        $this->assertSame(0, Task::count());
    }

    public function test_the_same_person_listed_twice_is_assigned_and_emailed_once(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent');

        $this->create($a, $this->makeBoard($a), [$b->id, $b->id]);

        Mail::assertQueued(TaskAssignedMail::class, 1);
        $this->assertCount(1, Task::firstOrFail()->assignees);
    }

    // ── The email ───────────────────────────────────────────────────────────

    public function test_the_assignee_is_emailed_when_a_task_is_created_for_them(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent'); // default level: moves only — assignment must still reach them

        $this->create($a, $this->makeBoard($a), [$b->id]);

        Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail) => $mail->hasTo($b->email));
        Mail::assertQueued(TaskAssignedMail::class, 1);
    }

    public function test_assigning_a_card_from_the_drawer_emails_the_new_assignee(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $board = $this->makeBoard($a);
        $task = $this->makeTask($board, $a);

        Livewire::actingAs($a)->test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $b->id);

        Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail) => $mail->hasTo($b->email));
    }

    public function test_removing_an_assignee_does_not_send_the_assigned_email(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $board = $this->makeBoard($a);
        $task = $this->makeTask($board, $a);
        $task->assignees()->attach($b->id);

        Livewire::actingAs($a)->test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $b->id);

        Mail::assertNotQueued(TaskAssignedMail::class);
    }

    public function test_assigning_a_task_to_yourself_sends_no_email(): void
    {
        Mail::fake();
        $a = $this->makeUser();

        $this->create($a, $this->makeBoard($a), [$a->id]);

        Mail::assertNotQueued(TaskAssignedMail::class);
    }

    public function test_a_user_who_turned_task_email_off_is_not_emailed(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent', User::TASK_NOTIFICATIONS_OFF);

        $this->create($a, $this->makeBoard($a), [$b->id]);

        Mail::assertNotQueued(TaskAssignedMail::class);
        $this->assertCount(1, Task::firstOrFail()->assignees, 'still assigned, just not emailed');
    }

    public function test_the_email_names_who_assigned_it_and_links_to_the_board(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $board = $this->makeBoard($a, 'Support');
        $task = $this->makeTask($board, $a, dueAt: now()->addDay());

        $mail = new TaskAssignedMail($task, $board, $a->name);

        $mail->assertSeeInHtml($a->name);
        $mail->assertSeeInHtml($task->title);
        $mail->assertSeeInHtml(route('boards.show', $board), false);
        $mail->assertSeeInHtml('Due');
        $this->assertStringContainsString($a->name, $mail->envelope()->subject);
        $this->assertStringContainsString($task->title, $mail->envelope()->subject);
    }

    public function test_an_assignee_on_all_activity_is_not_sent_two_emails_for_one_assignment(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser('agent', User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($a);
        $task = $this->makeTask($board, $a);

        Livewire::actingAs($a)->test(TaskBoard::class, ['board' => $board])
            ->call('openTask', $task->id)
            ->call('toggleAssignee', $b->id);

        // Run the queued activity listener synchronously for the same event.
        app(\App\Listeners\SendTaskActivityEmail::class)
            ->handle(new TaskBoardChanged((int) $board->id, TaskBoardChanged::REASON_ASSIGNED, $task->id, $a->id));

        Mail::assertQueued(TaskAssignedMail::class, 1);
        Mail::assertNotQueued(TaskActivityMail::class, fn (TaskActivityMail $mail) => $mail->hasTo($b->email));
    }

    public function test_assigned_notifications_follow_the_user_preference(): void
    {
        $this->assertTrue($this->makeUser('agent', User::TASK_NOTIFICATIONS_TRANSITIONS)->wantsTaskNotificationsFor('assigned'));
        $this->assertTrue($this->makeUser('agent', User::TASK_NOTIFICATIONS_ALL)->wantsTaskNotificationsFor('assigned'));
        $this->assertFalse($this->makeUser('agent', User::TASK_NOTIFICATIONS_OFF)->wantsTaskNotificationsFor('assigned'));
    }

    // ── "My tasks": the assignee sees it as their own ──────────────────────

    public function test_my_tasks_lists_open_cards_assigned_to_me_across_boards(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $one = $this->makeTask($this->makeBoard($a, 'Support'), $a, title: 'Fix the invoice');
        $two = $this->makeTask($this->makeBoard($a, 'Sales'), $a, title: 'Chase the quote');
        $one->assignees()->attach($b->id);
        $two->assignees()->attach($b->id);

        $this->actingAs($b)->get(route('tasks.mine'))
            ->assertOk()
            ->assertSee('Fix the invoice')
            ->assertSee('Chase the quote')
            ->assertSee('Support')
            ->assertSee('Sales');
    }

    public function test_my_tasks_hides_other_peoples_done_and_archived_cards(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $other = $this->makeUser('agent');
        $live = $this->makeBoard($a, 'Live');

        $mine = $this->makeTask($live, $a, title: 'Mine and open');
        $done = $this->makeTask($live, $a, 'done', 'Mine but finished');
        $theirs = $this->makeTask($live, $a, title: 'Someone elses');
        $archived = $this->makeTask($this->makeBoard($a, 'Old', archived: true), $a, title: 'On an archived board');
        foreach ([$mine, $done, $archived] as $task) {
            $task->assignees()->attach($b->id);
        }
        $theirs->assignees()->attach($other->id);

        $this->actingAs($b)->get(route('tasks.mine'))
            ->assertSee('Mine and open')
            ->assertDontSee('Mine but finished')
            ->assertDontSee('Someone elses')
            ->assertDontSee('On an archived board');
    }

    public function test_my_tasks_puts_overdue_first_and_says_so(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $board = $this->makeBoard($a);
        $later = $this->makeTask($board, $a, title: 'Due next week', dueAt: now()->addDays(7));
        $late = $this->makeTask($board, $a, title: 'Already late', dueAt: now()->subDay());
        $later->assignees()->attach($b->id);
        $late->assignees()->attach($b->id);

        $this->actingAs($b)->get(route('tasks.mine'))
            ->assertOk()
            ->assertSeeInOrder(['Overdue', 'Already late', 'Due next week']);
    }

    public function test_my_tasks_has_an_empty_state(): void
    {
        $this->actingAs($this->makeUser('agent'))->get(route('tasks.mine'))
            ->assertOk()
            ->assertSee('Nothing assigned to you');
    }

    public function test_my_tasks_requires_the_tasks_permission(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);

        $this->actingAs($nobody)->get(route('tasks.mine'))->assertForbidden();
    }

    public function test_the_sidebar_shows_my_tasks_with_the_open_count(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $board = $this->makeBoard($a);
        $this->makeTask($board, $a)->assignees()->attach($b->id);
        $this->makeTask($board, $a, 'done')->assignees()->attach($b->id);

        $html = $this->actingAs($b)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString(route('tasks.mine'), $html);
        $this->assertMatchesRegularExpression('/My tasks[\s\S]{0,300}>\s*1\s*</', $html);
    }

    public function test_the_dashboard_needs_you_panel_counts_tasks_assigned_to_me(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('agent');
        $this->makeTask($this->makeBoard($a), $a)->assignees()->attach($b->id);

        $this->actingAs($b)->get(route('dashboard'))->assertSee('Tasks assigned to you');
    }
}
