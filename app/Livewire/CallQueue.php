<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallQueueEntry;
use Livewire\Component;

class CallQueue extends Component
{
    public function render()
    {
        $entries = CallQueueEntry::query()
            ->waiting()
            ->get();

        return view('livewire.call-queue', [
            'queueEntries' => $entries,
        ]);
    }
}
