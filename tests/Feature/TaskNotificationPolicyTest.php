<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Livewire\TaskBoard;
use App\Mail\TaskActivityMail;
use App\Mail\TaskStatusChangedMail;
use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskNotificationRecipients;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notification policy: who gets emailed about a card, and when.
 *
 * The audience is a union of three overlapping groups, so the interesting
 * failures are all "the same person appears twice", "the person who acted gets
 * told about their own action", and "someone who muted it anyway". Each of
 * those gets a test rather than a code comment.
 */
class TaskNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    /**
     * $role is optional because most of this file tests the audience resolver,
     * which does not consult permissions. Tests that actually render the board
     * need a real role: an unprivileged user cannot mount the component, and
     * Livewire surfaces that as a malformed snapshot rather than a clean 403.
     */
    private function makeUser(
        string $level = User::TASK_NOTIFICATIONS_TRANSITIONS,
        ?string $role = null,
    ): User {
        $user = User::factory()->create([
            'is_active' => true,
            'task_notifications' => $level,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

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
            'title' => 'Refund the duplicate charge',
            'status' => $status,
            'position' => $position,
        ]);
    }

    public function test_audience_is_owner_assignees_and_watchers(): void
    {
        $owner = $this->makeUser();
        $assignee = $this->makeUser();
        $watcher = $this->makeUser();
        $bystander = $this->makeUser();
        $actor = $this->makeUser();

        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->assignees()->attach($assignee->id);
        $task->watchers()->attach($watcher->id);

        $ids = $this->resolve($task, $actor->id, 'transition');

        $this->assertContains($owner->id, $ids, 'the board owner stays in the audience');
        $this->assertContains($assignee->id, $ids);
        $this->assertContains($watcher->id, $ids);
        $this->assertNotContains($bystander->id, $ids, 'a user with no stake in the card is not notified');
    }

    public function test_resolved_recipients_are_actually_emailed_by_the_listener(): void
    {
        Mail::fake();

        $owner = $this->makeUser();
        $assignee = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->assignees()->attach($assignee->id);

        $actor = $this->makeUser();
        TaskStatusChanged::dispatch($task, 'todo', 'done', $actor->id);

        Mail::assertQueued(TaskStatusChangedMail::class, 2);
        Mail::assertQueued(
            TaskStatusChangedMail::class,
            fn (TaskStatusChangedMail $mail) => $mail->hasTo($owner->email)
        );
        Mail::assertQueued(
            TaskStatusChangedMail::class,
            fn (TaskStatusChangedMail $mail) => $mail->hasTo($assignee->email)
        );
    }

    public function test_a_person_in_several_groups_is_emailed_once(): void
    {
        Mail::fake();

        // One user who is simultaneously the board owner, an assignee and a
        // watcher - the union must not produce three copies.
        $overlap = $this->makeUser();
        $board = $this->makeBoard($overlap);
        $task = $this->makeTask($board, $overlap);
        $task->assignees()->attach($overlap->id);
        $task->watchers()->attach($overlap->id);

        $actor = $this->makeUser();
        TaskStatusChanged::dispatch($task, 'todo', 'done', $actor->id);

        Mail::assertQueued(TaskStatusChangedMail::class, 1);
    }

    public function test_the_actor_is_not_emailed_about_their_own_change(): void
    {
        Mail::fake();

        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->assignees()->attach($owner->id);

        // The board owner moving their own card is the single most common
        // action on the board; mailing them about it is pure noise.
        TaskStatusChanged::dispatch($task, 'todo', 'done', $owner->id);

        Mail::assertNothingQueued();
    }

    public function test_off_users_are_never_emailed(): void
    {
        $owner = $this->makeUser();
        $muted = $this->makeUser(User::TASK_NOTIFICATIONS_OFF);
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->watchers()->attach($muted->id);

        $actor = $this->makeUser();

        $this->assertNotContains($muted->id, $this->resolve($task, $actor->id, 'transition'));
        $this->assertNotContains($muted->id, $this->resolve($task, $actor->id, 'activity'));
    }

    public function test_transitions_level_ignores_activity_but_not_moves(): void
    {
        Mail::fake();

        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);

        // A comment on a card whose only other participant is on the default
        // level must not produce mail.
        $task->watchers()->attach($this->makeUser()->id);
        $actor = $this->makeUser();

        TaskBoardChanged::dispatch($board->id, TaskBoardChanged::REASON_COMMENTED, $task->id, $actor->id);

        Mail::assertNothingQueued();
    }

    public function test_all_level_receives_activity_mail(): void
    {
        Mail::fake();

        $owner = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);

        $actor = $this->makeUser();
        TaskBoardChanged::dispatch($board->id, TaskBoardChanged::REASON_COMMENTED, $task->id, $actor->id);

        Mail::assertQueued(TaskActivityMail::class, 1);
        Mail::assertQueued(
            TaskActivityMail::class,
            fn (TaskActivityMail $mail) => $mail->reason === TaskBoardChanged::REASON_COMMENTED
                && $mail->hasTo($owner->email)
        );
    }

    public function test_all_level_also_receives_move_mail(): void
    {
        Mail::fake();

        $owner = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);

        $actor = $this->makeUser();
        TaskStatusChanged::dispatch($task, 'todo', 'done', $actor->id);

        Mail::assertQueued(TaskStatusChangedMail::class, 1);
    }

    public function test_creating_a_card_does_not_email_anyone(): void
    {
        Mail::fake();

        $owner = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);

        $actor = $this->makeUser();
        TaskBoardChanged::dispatch($board->id, TaskBoardChanged::REASON_CREATED, $task->id, $actor->id);

        Mail::assertNothingQueued();
    }

    public function test_a_deleted_card_is_still_reported_to_all_level_watchers(): void
    {
        Mail::fake();

        $owner = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->delete();

        $actor = $this->makeUser();
        TaskBoardChanged::dispatch($board->id, TaskBoardChanged::REASON_DELETED, $task->id, $actor->id);

        // The row is soft-deleted, so this proves the listener reads it back
        // withTrashed() instead of silently doing nothing.
        Mail::assertQueued(TaskActivityMail::class, 1);
    }

    public function test_deactivated_users_are_skipped(): void
    {
        $owner = $this->makeUser();
        $leaver = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);
        $task->watchers()->attach($leaver->id);

        $leaver->forceFill(['is_active' => false])->save();

        $actor = $this->makeUser();

        $this->assertNotContains($leaver->id, $this->resolve($task, $actor->id, 'transition'));
    }

    public function test_resolver_returns_nothing_for_a_task_whose_board_is_gone(): void
    {
        $owner = $this->makeUser();
        $board = $this->makeBoard($owner);
        $task = $this->makeTask($board, $owner);

        $task->setRelation('board', null);

        $recipients = (new TaskNotificationRecipients)->forTask($task, null, 'transition');

        $this->assertTrue($recipients->isEmpty());
    }

    /**
     * A retraction must not be announced as an arrival.
     *
     * This is a regression test for a bug in the wiring, not the template:
     * `deleteComment()` fired the same reason as `addComment()`, so removing a
     * comment mailed every subscriber "left a comment". The usual reason to
     * delete a comment is to take it back, and the system was announcing the
     * retraction to everyone on the card.
     *
     * Driven through the real Livewire action rather than by dispatching the
     * event by hand. A hand-dispatched test asserts only that the mailable
     * renders the right words for a reason someone chose on purpose - it passes
     * happily while the component emits the wrong reason, which is the bug.
     */
    public function test_removing_a_comment_mails_a_retraction_not_an_arrival(): void
    {
        Mail::fake();

        $author = $this->makeUser(User::TASK_NOTIFICATIONS_ALL, User::ROLE_SUPER_ADMIN);
        $watcher = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($author);
        $task = $this->makeTask($board, $author);
        $task->watchers()->attach($watcher->id);
        $comment = $task->comments()->create([
            'user_id' => $author->id,
            'message' => 'Withdrawing this',
        ]);

        Livewire::actingAs($author)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->call('deleteComment', $comment->id)
            ->assertHasNoErrors();

        Mail::assertQueued(TaskActivityMail::class, function (TaskActivityMail $mail) {
            if ($mail->reason !== TaskBoardChanged::REASON_COMMENT_REMOVED) {
                return false;
            }

            // Assert on the rendered body rather than the envelope: it is what
            // the recipient actually reads, and it does not depend on how
            // Laravel happens to expose the subject on a Mailable.
            $body = $mail->render();

            return str_contains($body, 'removed a comment')
                && ! str_contains($body, 'left a comment');
        });
    }

    public function test_a_deadline_change_is_not_mailed_as_a_description_edit(): void
    {
        Mail::fake();

        $actor = $this->makeUser(User::TASK_NOTIFICATIONS_ALL, User::ROLE_SUPER_ADMIN);
        $watcher = $this->makeUser(User::TASK_NOTIFICATIONS_ALL);
        $board = $this->makeBoard($actor);
        $task = $this->makeTask($board, $actor);
        $task->watchers()->attach($watcher->id);

        Livewire::actingAs($actor)
            ->test(TaskBoard::class, ['boardId' => $board->id])
            ->set('openTaskId', $task->id)
            ->call('saveTriage')
            ->assertHasNoErrors();

        Mail::assertQueued(TaskActivityMail::class, function (TaskActivityMail $mail) {
            if ($mail->reason !== TaskBoardChanged::REASON_TRIAGE_UPDATED) {
                return false;
            }

            $body = $mail->render();

            return str_contains($body, 'changed the deadline, priority or estimate')
                && ! str_contains($body, 'updated the description');
        });
    }

    public function test_an_unrecognised_stored_level_falls_back_to_the_default(): void
    {
        $user = User::factory()->create(['task_notifications' => 'loud']);

        $this->assertSame(User::TASK_NOTIFICATIONS_TRANSITIONS, $user->taskNotificationLevel());
        $this->assertTrue($user->wantsTaskNotificationsFor('transition'));
        $this->assertFalse($user->wantsTaskNotificationsFor('activity'));
    }

    /**
     * @return list<int>
     */
    private function resolve(Task $task, ?int $actorId, string $kind): array
    {
        return (new TaskNotificationRecipients)
            ->forTask($task, $actorId, $kind)
            ->map(fn (User $user) => (int) $user->id)
            ->all();
    }
}
