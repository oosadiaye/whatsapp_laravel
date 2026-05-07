<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Picks the next available agent for round-robin auto-assignment of
 * incoming conversations. Used by InboundMessageProcessor and
 * InboundCallProcessor on the firstOrCreate-of-Conversation branch.
 *
 * "Available" means: role=agent, is_active=true, last_seen_at within
 * the last AVAILABILITY_WINDOW_MINUTES. The poll-driven heartbeat in
 * App\Livewire\RealtimePulse keeps last_seen_at fresh while the agent
 * has the app open.
 *
 * Fairness: rotation orders by last_assigned_at ASC NULLS FIRST. New
 * agents and returning-from-break agents naturally get priority.
 *
 * Race safety: the SELECT-then-UPDATE pair runs inside DB::transaction()
 * with lockForUpdate(), so two simultaneous webhooks can't both pick
 * the same agent (which would skew the rotation and assign two
 * conversations to one person while another agent gets nothing).
 */
class RoundRobinAssigner
{
    public const AVAILABILITY_WINDOW_MINUTES = 2;

    /**
     * Atomically pick the next available agent and stamp them as the
     * most-recently-assigned. Returns null if no agent is online.
     */
    public function next(): ?User
    {
        return DB::transaction(function (): ?User {
            $agent = User::query()
                ->where('role', User::ROLE_AGENT)
                ->where('is_active', true)
                ->where('last_seen_at', '>=', now()->subMinutes(self::AVAILABILITY_WINDOW_MINUTES))
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
