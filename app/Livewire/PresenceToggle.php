<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Sidebar dropdown letting an agent pick their explicit presence status:
 * available / busy / away. Mounted only for users with role=agent in
 * resources/views/layouts/navigation.blade.php — the component itself
 * does NOT enforce role (so admins/managers calling setStatus do not
 * error). The agent-only mount is a UX choice, not a security boundary.
 *
 * Status changes write two columns in a single UPDATE:
 *   presence_status         — read by RoundRobinAssigner::next()
 *   presence_status_set_at  — read by the view's diffForHumans tooltip
 *
 * Invalid status strings are silently rejected (defense in depth — the
 * rendered view only emits valid values, so this only matters under
 * tampered Livewire requests).
 */
class PresenceToggle extends Component
{
    public string $status = User::PRESENCE_AVAILABLE;

    public function mount(): void
    {
        $this->status = Auth::user()->presence_status ?? User::PRESENCE_AVAILABLE;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, User::PRESENCE_STATUSES, true)) {
            return;
        }

        Auth::user()->forceFill([
            'presence_status' => $status,
            'presence_status_set_at' => now(),
        ])->save();

        $this->status = $status;
    }

    public function render()
    {
        return view('livewire.presence-toggle', [
            'setAt' => Auth::user()->presence_status_set_at,
        ]);
    }
}
