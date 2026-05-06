<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CallLog;
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
            ]);
        }

        $callQuery = CallLog::query()
            ->where('direction', CallLog::DIRECTION_INBOUND)
            ->whereIn('status', CallLog::STATUSES_IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->with(['contact', 'whatsappInstance']);

        if ($user->can('conversations.view_all')) {
            // Admin / manager / super_admin: every call on a conversation
            // owned by this user (account scope).
            $callQuery->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id));
        } else {
            // Agent (view_assigned only): assigned to me OR unassigned pool.
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

        return view('livewire.realtime-pulse', [
            'inflightCalls' => $inflightCalls,
            'unreadMessages' => 0,  // wired up in Task 6
        ]);
    }
}
