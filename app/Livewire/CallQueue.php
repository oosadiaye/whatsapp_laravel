<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallQueueEntry;
use Livewire\Component;

class CallQueue extends Component
{
    public function render()
    {
        // Re-authorize on every render — Livewire updates bypass the route
        // permission gate. /workspace requires view_all OR view_assigned.
        abort_unless(
            (bool) (auth()->user()?->can('conversations.view_all')
                || auth()->user()?->can('conversations.view_assigned')),
            403,
        );

        $entries = CallQueueEntry::query()
            ->waiting()
            ->get();

        return view('livewire.call-queue', [
            'queueEntries' => $entries,
        ]);
    }
}
