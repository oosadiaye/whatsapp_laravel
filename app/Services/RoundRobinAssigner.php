<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next available agent for round-robin auto-assignment of
 * incoming conversations. Used by InboundMessageProcessor and
 * InboundCallProcessor on the firstOrCreate-of-Conversation branch.
 *
 * "Available" means: role=agent, is_active=true, presence_status != 'away',
 * and last_seen_at within the last AVAILABILITY_WINDOW_MINUTES. The
 * 'busy' presence_status remains in rotation — busy is a social signal
 * broadcast to teammates, not a routing rule. The poll-driven heartbeat
 * in App\Livewire\RealtimePulse keeps last_seen_at fresh while the agent
 * has the app open.
 *
 * Fairness: rotation orders by last_assigned_at ASC NULLS FIRST. New
 * agents and returning-from-break agents naturally get priority.
 *
 * Race safety: the SELECT-then-UPDATE pair runs inside DB::transaction()
 * with lockForUpdate(), so two simultaneous webhooks can't both pick
 * the same agent (which would skew the rotation and assign two
 * conversations to one person while another agent gets nothing).
 *
 * Capacity cap (Phase 14.4): an agent whose count of conversations with
 * last_inbound_at within ACTIVE_WINDOW_HOURS is at or above the global
 * cap (settings.round_robin_cap_per_agent, default 5) is excluded. When
 * all eligible agents are at cap, next() returns null and the conversation
 * stays unassigned — managers handle saturation via the existing Unassigned
 * filter on /conversations.
 */
class RoundRobinAssigner
{
    public const AVAILABILITY_WINDOW_MINUTES = 2;

    /**
     * Window for the per-agent capacity cap. A conversation counts toward
     * an agent's "active load" only if its last_inbound_at is within this
     * many hours of now(). 24 hours matches WhatsApp customer-support
     * cadence — threads quieter than that are considered dormant and the
     * agent is treated as free of them for routing purposes.
     */
    public const ACTIVE_WINDOW_HOURS = 24;

    /**
     * Atomically pick the next available agent and stamp them as the
     * most-recently-assigned. Returns null if no agent is online.
     */
    public function next(): ?User
    {
        // Cap is read OUTSIDE the transaction — it's a slow-changing config
        // value, not a routing-time race participant. Cast to (int) defensively:
        // Setting::get returns string from the DB, and a non-numeric value
        // (manual tampering) coerces to 0, which means "manual-only mode" — a
        // safe failure direction (errs toward not-routing rather than over-
        // routing).
        $cap = (int) Setting::get('round_robin_cap_per_agent', 5);
        $cutoff = now()->subHours(self::ACTIVE_WINDOW_HOURS);

        return DB::transaction(function () use ($cap, $cutoff): ?User {
            $agent = User::query()
                ->where('role', User::ROLE_AGENT)
                ->where('is_active', true)
                ->where('presence_status', '!=', User::PRESENCE_AWAY)
                ->where('last_seen_at', '>=', now()->subMinutes(self::AVAILABILITY_WINDOW_MINUTES))
                ->whereRaw(
                    '(SELECT COUNT(*) FROM conversations
                      WHERE conversations.assigned_to_user_id = users.id
                        AND conversations.last_inbound_at >= ?) < ?',
                    [$cutoff, $cap]
                )
                ->orderByRaw('last_assigned_at IS NULL DESC, last_assigned_at ASC')
                ->lockForUpdate()
                ->first();

            if ($agent !== null) {
                $agent->forceFill(['last_assigned_at' => now()])->save();
            }

            return $agent;
        });
    }
}
