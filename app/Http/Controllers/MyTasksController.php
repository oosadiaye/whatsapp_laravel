<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\View\View;

/**
 * "My tasks": every unfinished card assigned to the signed-in user, across all
 * boards, so being handed work does not depend on knowing which board to open.
 *
 * Read-only on purpose — the work itself happens on the board, which is one
 * click away from each row.
 */
class MyTasksController extends Controller
{
    public function index(): View
    {
        $tasks = Task::query()
            ->openAssignedTo((int) auth()->id(), TaskStatus::completedSlugs())
            ->with(['board:id,name,slug', 'user:id,name'])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('priority')
            ->get();

        // Overdue first because it is the part that is already costing something,
        // then everything with a date, then the cards nobody has dated.
        $overdue = $tasks->filter(fn (Task $task): bool => $task->due_at !== null && $task->due_at->isPast());
        $scheduled = $tasks->filter(fn (Task $task): bool => $task->due_at !== null && ! $task->due_at->isPast());
        $undated = $tasks->filter(fn (Task $task): bool => $task->due_at === null);

        return view('tasks.mine', [
            'groups' => [
                'Overdue' => $overdue,
                'Upcoming' => $scheduled,
                'No due date' => $undated,
            ],
            'total' => $tasks->count(),
        ]);
    }
}
