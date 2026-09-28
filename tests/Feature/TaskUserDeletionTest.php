<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review #1 (CRITICAL): boards are a SHARED company-wide workspace and user_id is
 * creator audit metadata. Deleting a user (routine offboarding via /users) must NOT
 * cascade-hard-delete the shared board + its tasks/comments. The FKs null the creator
 * instead. Before the fix, onDelete('cascade') wiped this data irreversibly.
 */
class TaskUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_user_preserves_shared_boards_tasks_and_comments(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();

        // A board the creator made (shared) + a task + a comment on it.
        $board = Board::factory()->create(['user_id' => $creator->id]);
        $task = Task::factory()->create(['user_id' => $creator->id, 'board_id' => $board->id]);
        $comment = TaskComment::factory()->create(['user_id' => $creator->id, 'task_id' => $task->id]);

        // A task the creator made on SOMEONE ELSE'S board + a comment left there.
        $otherBoard = Board::factory()->create(['user_id' => $other->id]);
        $taskOnOther = Task::factory()->create(['user_id' => $creator->id, 'board_id' => $otherBoard->id]);
        $commentOnOther = TaskComment::factory()->create(['user_id' => $creator->id, 'task_id' => $taskOnOther->id]);

        $creator->delete();

        // Shared data survives; only the creator reference is nulled.
        $this->assertDatabaseHas('boards', ['id' => $board->id, 'user_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'user_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $taskOnOther->id, 'user_id' => null]);
        $this->assertDatabaseHas('task_comments', ['id' => $comment->id, 'user_id' => null]);
        $this->assertDatabaseHas('task_comments', ['id' => $commentOnOther->id, 'user_id' => null]);

        // The other user's board is completely untouched.
        $this->assertDatabaseHas('boards', ['id' => $otherBoard->id, 'user_id' => $other->id]);
    }
}
