<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

class InFlightCall extends Component
{
    public int $conversationId;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function render()
    {
        // Most-recent call_log for this conversation, only if still in-flight
        // and started within the last 30 minutes (so old hung calls don't
        // appear forever).
        $call = CallLog::query()
            ->where('conversation_id', $this->conversationId)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest()
            ->first();

        return view('livewire.in-flight-call', [
            'call' => $call,
        ]);
    }
}
