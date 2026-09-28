<?php

namespace Database\Factories;

use App\Models\TaskActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskActivity>
 *
 * task_id and board_id have no default on purpose. The other factories in this
 * schema derive the board from the task (or the user from nothing), which is
 * convenient until you need to state a case that must *not* line up. Here, a row
 * without both is not a valid log entry, so the caller has to say which card and
 * which board it is about.
 */
class TaskActivityFactory extends Factory
{
    protected $model = TaskActivity::class;

    public function definition(): array
    {
        return [
            'task_id' => null,
            'board_id' => null,
            'user_id' => null,
            'type' => TaskActivity::TYPE_UPDATED,
            'from_status' => null,
            'to_status' => null,
            'metadata' => null,
        ];
    }

    public function forTask(int $taskId, int $boardId): static
    {
        return $this->state(fn (): array => [
            'task_id' => $taskId,
            'board_id' => $boardId,
        ]);
    }

    public function transition(string $from = 'todo', string $to = 'doing'): static
    {
        return $this->state(fn (): array => [
            'type' => TaskActivity::TYPE_STATUS_CHANGED,
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function by(?int $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
