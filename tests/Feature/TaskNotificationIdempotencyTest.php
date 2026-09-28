<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\TaskBoardChanged;
use App\Events\TaskStatusChanged;
use App\Listeners\SendTaskStatusEmail;
use App\Mail\TaskActivityMail;
use App\Mail\TaskStatusChangedMail;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskActivityNotification;
use App\Models\User;
use App\Support\TaskNotificationLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Durable notification idempotency.
 *
 * The failure being defended against is concrete: both mail listeners are queued
 * and both fan out in a loop, so a throw partway round the recipients retries
 * the whole listener job and - without a ledger - re-mails everyone already
 * handled. Most of these tests therefore dispatch the *same event object twice*,
 * which is what a queue retry does.
 *
 * QUEUE_CONNECTION is sync in tests, so `event()` runs the whole listener chain
 * inline. That is an advantage: a dispatch is the real production path, and
 * nothing here has to reach past the event to call a listener by hand.
 *
 * Note the audience arithmetic in every test: the board owner is always a
 * recipient, so the actor is passed as `actorId` and the resolver excludes them.
 * That leaves the watchers as the only people counted, which keeps the expected
 * numbers below obvious.
 *
 * What is deliberately not asserted is exactly-once delivery, because it is not
 * what this does. There is a window between the queue accepting a mailable and
 * the ledger row committing, and a crash inside it duplicates. The tests state
 * that ceiling rather than implying a stronger property than the code has.
 */
class TaskNotificationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TaskStatusSeeder::class);
    }

    private function makeUser(
        string $level = User::TASK_NOTIFICATIONS_TRANSITIONS,
    ): User {
        $user = User::factory()->create([
            'is_active' => true,
            'task_notifications' => $level,
        ]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);

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

    private function makeTask(Board $board, User $creator): Task
    {
        // A unique position within the column: the schema forbids two cards
        // sharing a (board, status, position).
        $position = (int) Task::where('board_id', $board->id)->where('status', 'todo')->max('position') + 1;

        return Task::create([
            'user_id' => $creator->id,
            'board_id' => $board->id,
            'title' => 'A card',
            'status' => 'todo',
            'position' => $position,
        ]);
    }

    /**
     * A card with the given number of watchers, returning the actor so tests can
     * pass a real actorId and keep the owner out of the recipient count.
     *
     * @return array{0: Task, 1: Board, 2: User}
     */
    private function cardWithWatchers(string $level = User::TASK_NOTIFICATIONS_TRANSITIONS, int $count = 1): array
    {
        $actor = $this->makeUser($level);
        $board = $this->makeBoard($actor);
        $task = $this->makeTask($board, $actor);

        for ($i = 0; $i < $count; $i++) {
            $task->watchers()->attach($this->makeUser($level)->id);
        }

        return [$task, $board, $actor];
    }

    public function test_a_retried_transition_listener_does_not_mail_the_same_person_twice(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers();
        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);

        // A queue retry re-delivers the same event object. Before the ledger
        // this queued a second copy of the same mail.
        event($event);
        event($event);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TaskStatusChangedMail::class);
    }

    public function test_a_retried_activity_listener_does_not_mail_the_same_person_twice(): void
    {
        Mail::fake();

        [$task, $board, $actor] = $this->cardWithWatchers(User::TASK_NOTIFICATIONS_ALL);

        $event = new TaskBoardChanged(
            $board->id,
            TaskBoardChanged::REASON_COMMENTED,
            $task->id,
            $actor->id,
        );

        event($event);
        event($event);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TaskActivityMail::class);
    }

    public function test_a_retry_does_not_duplicate_the_history_entry_either(): void
    {
        [$task, , $actor] = $this->cardWithWatchers();

        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        event($event);
        event($event);

        // The unique event_key means a repeat write is an integrity violation,
        // and recordOnce() turns that into a no-op. Without that, a re-dispatch
        // would 500 the request that only wanted to move a card.
        $this->assertSame(1, TaskActivity::where('event_key', $event->eventKey)->count());
    }

    public function test_every_recipient_on_one_event_is_still_mailed_once_each(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers(User::TASK_NOTIFICATIONS_ALL, 3);
        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);

        event($event);
        event($event);

        // Three watchers, one copy each. The guard is per (activity, user), so a
        // second run skips all three without eating anyone's first copy.
        Mail::assertQueuedCount(3);
    }

    public function test_a_partial_failure_retry_does_not_duplicate_the_earlier_recipients(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers(User::TASK_NOTIFICATIONS_ALL, 2);
        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        event($event);

        $activity = TaskActivity::where('event_key', $event->eventKey)->firstOrFail();

        // The retry shape, reconstructed by resetting the mail spy: the first
        // attempt went out, then the job died partway round the loop.
        Mail::fake();
        event($event);

        Mail::assertQueuedCount(0);

        // And the ledger still holds exactly one row per recipient - the retry
        // did not add second rows for the people it correctly skipped.
        $this->assertSame(2, TaskActivityNotification::where('activity_id', $activity->id)->count());
    }

    public function test_a_recipient_not_yet_notified_is_still_mailed_on_retry(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers(User::TASK_NOTIFICATIONS_ALL, 2);
        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        event($event);

        $activity = TaskActivity::where('event_key', $event->eventKey)->firstOrFail();

        // Drop the second recipient's row to model the job dying between the
        // two queue() calls. The retry must not skip them just because someone
        // else on the same activity has already been recorded.
        $firstRecipient = (int) $activity->notifications()->value('user_id');
        TaskActivityNotification::where('activity_id', $activity->id)
            ->where('user_id', $firstRecipient)
            ->delete();

        Mail::fake();
        event($event);

        Mail::assertQueuedCount(1);
    }

    public function test_the_ledger_records_a_second_attempt_as_a_duplicate_rather_than_throwing(): void
    {
        $ledger = new TaskNotificationLedger;

        $this->assertTrue($ledger->markSent(1, 2));

        // The unique index is what makes this safe. A read-then-write would
        // interleave, and a plain create() would blow up the whole job on the
        // duplicate - turning a dedupe guard into a new source of 500s.
        $this->assertFalse($ledger->markSent(1, 2));
    }

    public function test_a_missing_log_row_still_sends_the_mail(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers();

        // An event whose activity row never landed (recorder failed, or the
        // event was dispatched with recording off). There is nothing to dedupe
        // against, and bookkeeping must never be able to silence the product
        // feature.
        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        $this->assertNull((new TaskNotificationLedger)->activityIdFor($event));

        (new SendTaskStatusEmail)->handle($event);

        Mail::assertQueued(TaskStatusChangedMail::class);
    }

    public function test_two_separate_moves_each_notify_the_watcher(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers();

        event(new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id));
        $second = new TaskStatusChanged($task, 'in_progress', 'review', $actor->id);
        event($second);

        // The guard is per activity, not per person: a watcher told twice about
        // two real moves has been told correctly. Dedupe must not quietly become
        // "one email per card, forever".
        Mail::assertQueuedCount(2);
    }

    public function test_the_same_move_made_twice_by_hand_is_two_notifications(): void
    {
        Mail::fake();

        [$task, , $actor] = $this->cardWithWatchers();

        // Two *distinct* events describing an identical move. These really are
        // two things that happened, and the watcher gets the honest number of
        // emails - the ledger keys on the event, not on the shape of the move.
        event(new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id));
        event(new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id));

        Mail::assertQueuedCount(2);
    }

    public function test_the_recorder_stores_the_key_the_listener_looks_up_by(): void
    {
        [$task, , $actor] = $this->cardWithWatchers();

        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        event($event);

        // Exact correlation, not inference. This is the reason the key rides on
        // the event: reading it off another listener depends on registration
        // order, and "the newest matching row" is racy on a busy card.
        $this->assertSame(
            (int) TaskActivity::where('event_key', $event->eventKey)->value('id'),
            (new TaskNotificationLedger)->activityIdFor($event),
        );
    }

    public function test_event_keys_are_unique_per_event(): void
    {
        [$task, , $actor] = $this->cardWithWatchers();

        $a = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        $b = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);

        $this->assertNotSame($a->eventKey, $b->eventKey);
    }

    public function test_the_actor_is_never_mailed_about_their_own_move(): void
    {
        Mail::fake();

        $actor = $this->makeUser();
        $board = $this->makeBoard($actor);
        $task = $this->makeTask($board, $actor);
        $task->watchers()->attach($this->makeUser()->id);

        $event = new TaskStatusChanged($task, 'todo', 'in_progress', $actor->id);
        event($event);
        event($event);

        // The one queued copy is the watcher's. Dedupe must not be what makes
        // the actor's absence here - the resolver excludes them, and that has to
        // still be the reason.
        Mail::assertQueuedCount(1);
    }
}
