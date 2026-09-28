<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TaskStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds the four default board columns.
 *
 * Idempotent (upsert on slug) so re-seeding never duplicates a column. Later
 * statuses are added from Settings, not here.
 */
class TaskStatusSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['slug' => 'todo', 'name' => 'To Do', 'color' => 'gray', 'is_default' => true, 'is_completed' => false],
            ['slug' => 'in_progress', 'name' => 'In Progress', 'color' => 'blue', 'is_default' => false, 'is_completed' => false],
            ['slug' => 'review', 'name' => 'Review', 'color' => 'yellow', 'is_default' => false, 'is_completed' => false],
            ['slug' => 'done', 'name' => 'Done', 'color' => 'green', 'is_default' => false, 'is_completed' => true],
        ];

        foreach ($defaults as $index => $status) {
            TaskStatus::updateOrCreate(
                ['slug' => $status['slug']],
                [
                    'name' => $status['name'],
                    'color' => $status['color'],
                    'position' => $index,
                    'is_default' => $status['is_default'],
                    'is_completed' => $status['is_completed'],
                ],
            );
        }
    }
}
