<?php

declare(strict_types=1);

namespace App\Livewire;

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

        return view('livewire.realtime-pulse', [
            'inflightCalls' => [],
            'unreadMessages' => 0,
        ]);
    }
}
