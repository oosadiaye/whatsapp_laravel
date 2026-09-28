<?php

namespace Database\Factories;

use App\Models\Board;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'board_id' => Board::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'status' => 'todo',
            // Positions are unique per (board_id, status). A single task defaults to
            // 0; callers creating several tasks in the SAME column must pass distinct
            // positions (or go through TaskBoard::createTask, which reserves slots).
            'position' => 0,
        ];
    }
}
