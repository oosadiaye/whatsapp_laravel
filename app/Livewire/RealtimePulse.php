<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Real-time UX surface mounted on the layout. Polls every 3 seconds and
 * returns a unified payload of:
 *   - in-flight inbound calls visible to the current user (banner data)
 *   - unread message count across visible conversations (notification trigger)
 *
 * Permission scoping mirrors the inbox:
 *   - conversations.view_all → entire account
 *   - conversations.view_assigned → assigned to me + unassigned pool
 *
 * Anonymous users get an empty payload (no error, no banner) — the
 * @auth gate in app.blade.php means this is mostly belt-and-suspenders,
 * but the test exercises it explicitly for clarity.
 */
class RealtimePulse extends Component
{
    public function render()
    {
        $user = Auth::user();

        if ($user === null) {
            return view('livewire.realtime-pulse', [
                'inflightCalls' => [],
                'unreadMessages' => 0,
                'missedCallsCount' => 0,
            ]);
        }

        // Implicit-heartbeat presence: touch last_seen_at every 30 seconds.
        // The 3-second wire:poll cycle would otherwise produce ~20 writes/min
        // per agent. The 30s dedup window is well below the 2-min availability
        // threshold (RoundRobinAssigner::AVAILABILITY_WINDOW_MINUTES), so
        // freshness is preserved while write load drops by ~90%.
        if ($user->last_seen_at === null
            || $user->last_seen_at->lt(now()->subSeconds(30))) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }

        $callQuery = CallLog::query()
            ->where('direction', CallLog::DIRECTION_INBOUND)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->with(['contact', 'whatsappInstance']);

        // Single-tenant visibility (fb5a398):
        //   view_all      → every in-flight call in the company
        //   view_assigned → assigned to me OR unassigned pool (broader
        //                   than the inbox by design so every agent
        //                   sees a ringing unclaimed call — see
        //                   realtime-ux-bundle-design.md section
        //                   "Permission scoping")
        if (! $user->can('conversations.view_all')) {
            $callQuery->whereHas('conversation', fn ($q) =>
                $q->where(fn ($qq) =>
                    $qq->where('assigned_to_user_id', $user->id)
                       ->orWhereNull('assigned_to_user_id')
                )
            );
        }

        $inflightCalls = $callQuery
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($call) => [
                'id' => $call->id,
                'conversation_id' => $call->conversation_id,
                'contact_name' => $call->contact->name ?? null,
                'phone' => $call->from_phone,
                'instance_name' => $call->whatsappInstance->display_name
                    ?? $call->whatsappInstance->instance_name,
                'status' => $call->status,
                'started_at' => $call->started_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        // Unread message count across visible conversations — same scoping
        // rules as the call payload above.
        $messageQuery = Conversation::query();
        if (! $user->can('conversations.view_all')) {
            $messageQuery->where(fn ($q) =>
                $q->where('assigned_to_user_id', $user->id)
                  ->orWhereNull('assigned_to_user_id')
            );
        }
        $unreadMessages = (int) $messageQuery->sum('unread_count');

        // Missed-calls count for the sidebar "Calls" badge. Scoped identically
        // to the in-flight banner data above (view_all → account; view_assigned
        // → assigned-to-me + unassigned pool) so an agent's badge reflects calls
        // they could/should have answered. Window: last 24h — the call history
        // page is the source of truth for older missed calls; the badge is for
        // "did I miss something today?"
        $missedQuery = CallLog::query()
            ->where('direction', CallLog::DIRECTION_INBOUND)
            ->where('status', CallLog::STATUS_MISSED)
            ->where('created_at', '>=', now()->subDay());

        if (! $user->can('conversations.view_all')) {
            $missedQuery->whereHas('conversation', fn ($q) =>
                $q->where(fn ($qq) =>
                    $qq->where('assigned_to_user_id', $user->id)
                       ->orWhereNull('assigned_to_user_id')
                )
            );
        }
        $missedCallsCount = (int) $missedQuery->count();

        return view('livewire.realtime-pulse', [
            'inflightCalls' => $inflightCalls,
            'unreadMessages' => $unreadMessages,
            'missedCallsCount' => $missedCallsCount,
        ]);
    }
}
