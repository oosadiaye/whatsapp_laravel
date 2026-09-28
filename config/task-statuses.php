<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Task status fallback
|--------------------------------------------------------------------------
|
| Board columns are stored in the `task_statuses` table so administrators can
| add, rename, reorder or retire a column from Settings without a deploy.
| TaskStatus::columns() reads that table.
|
| The definitions below are ONLY a safety net, used when the table is empty
| (e.g. a fresh install whose seeder has not run) so the board still renders
| instead of throwing. They are not a second source of truth — once the table
| has rows it wins. Edit statuses in the app, not here.
|
*/

return [

    'statuses' => [
        'todo' => ['name' => 'To Do', 'color' => 'gray'],
        'in_progress' => ['name' => 'In Progress', 'color' => 'blue'],
        'review' => ['name' => 'Review', 'color' => 'yellow'],
        'done' => ['name' => 'Done', 'color' => 'green'],
    ],

    'default_status' => 'todo',

    'completed' => ['done'],

];
