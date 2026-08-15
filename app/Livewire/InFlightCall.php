<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use Livewire\Component;

class InFlightCall extends Component
{
    // conversationId is optional: when omitted (global layout mount) the
    // component shows ANY in-flight outbound call the current user can see,
    // so the auto-answering WebRTC banner is present no matter which page
    // the agent is on when the customer answers (fixes "ring then abrupt
    // end" caused by the banner only existing on the conversation page).
    public ?int $conversationId = null;

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function render()
    {
        // Most-recent outbound call that's still in-flight and within the
        // freshness window. Scoping:
        //   view_all      → any in-flight call (so an admin opening a
        //                   colleague's conversation can see and end an
        //                   ongoing call — single-tenant fb5a398)
        //   view_assigned → only calls THIS user placed (agent scope —
        //                   one agent doesn't get the End button for
        //                   another agent's active call)
        //
        // 30-min freshness window matches CleanupStaleCalls' threshold:
        // beyond that, the call is presumed orphaned and we don't show
        // a misleading in-flight UI.
        $user = auth()->user();

        $query = CallLog::query()
            ->when($this->conversationId !== null, fn ($q) => $q->where('conversation_id', $this->conversationId))
            ->where('direction', CallLog::DIRECTION_OUTBOUND)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30));

        if (! $user?->can('conversations.view_all')) {
            $query->where('placed_by_user_id', $user?->id);
        }

        $call = $query->latest()->first();

        return view('livewire.in-flight-call', [
            'call' => $call,
        ]);
    }
}
