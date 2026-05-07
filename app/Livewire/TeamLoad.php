<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Setting;
use App\Models\User;
use App\Services\RoundRobinAssigner;
use Livewire\Component;

/**
 * Manager-only team-load dashboard. Renders the agent roster as a
 * 4-column table polling every 10 seconds. Shows presence, active
 * conversation load (with the same definition RoundRobinAssigner
 * uses for capacity gating), and last-seen timestamp.
 *
 * Read-only. No inline actions — managers reassign conversations
 * via the assignee dropdown on the conversation page itself, and
 * manage user CRUD via /users.
 *
 * Mounted at /team via TeamLoadController, gated by the existing
 * permission:users.view middleware.
 *
 * Reuses RoundRobinAssigner::ACTIVE_WINDOW_HOURS and
 * AVAILABILITY_WINDOW_MINUTES so dashboard semantics never drift
 * from routing semantics.
 */
class TeamLoad extends Component
{
    public function render()
    {
        $cap = (int) Setting::get('round_robin_cap_per_agent', 5);
        $cutoff = now()->subHours(RoundRobinAssigner::ACTIVE_WINDOW_HOURS);
        $availabilityCutoff = now()->subMinutes(
            RoundRobinAssigner::AVAILABILITY_WINDOW_MINUTES
        );

        $agents = User::query()
            ->where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->withCount(['assignedConversations as active_count' => function ($q) use ($cutoff) {
                $q->where('last_inbound_at', '>=', $cutoff);
            }])
            ->orderBy('name')
            ->get();

        return view('livewire.team-load', [
            'agents' => $agents,
            'cap' => $cap,
            'availabilityCutoff' => $availabilityCutoff,
        ]);
    }
}
