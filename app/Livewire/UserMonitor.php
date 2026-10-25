<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Models\UserSession;
use App\Services\UserSessionMonitor;
use Livewire\Component;

/**
 * Who is signed in right now, from where, and the ability to sign them out.
 *
 * Viewing is gated by team.view (route + every render — Livewire updates bypass
 * route middleware). Signing people out additionally needs users.edit, and a
 * non-super-admin can never end a super admin's session. Nobody can end the
 * session they are using from here — that is what the normal Log out is for.
 */
class UserMonitor extends Component
{
    public ?string $notice = null;

    public function logoutSession(int $sessionId, UserSessionMonitor $monitor): void
    {
        $this->authorizeManage();

        $session = UserSession::with('user')->findOrFail($sessionId);
        abort_unless($this->canManage($session->user), 403);

        if ($session->session_id === session()->getId()) {
            $this->notice = __('That is the session you are using — use the normal Log out for it.');

            return;
        }

        $monitor->revoke($session);
        $this->notice = __(':name was signed out of that device.', ['name' => $session->user->name]);
    }

    public function logoutUser(int $userId, UserSessionMonitor $monitor): void
    {
        $this->authorizeManage();

        $target = User::findOrFail($userId);
        abort_unless($this->canManage($target), 403);

        $isMe = $target->is(auth()->user());
        $count = $monitor->revokeAllForUser($target, $isMe ? session()->getId() : null);

        $this->notice = trans_choice(':name was signed out of :count session.|:name was signed out of :count sessions.', $count, [
            'name' => $isMe ? __('You') : $target->name,
            'count' => $count,
        ]);
    }

    public function logoutEveryoneElse(UserSessionMonitor $monitor): void
    {
        $this->authorizeManage();

        $me = auth()->user();
        $total = 0;

        $userIds = UserSession::live()->distinct()->pluck('user_id');
        foreach (User::whereIn('id', $userIds)->get() as $target) {
            if (! $this->canManage($target)) {
                continue;
            }
            $total += $monitor->revokeAllForUser($target, $target->is($me) ? session()->getId() : null);
        }

        $this->notice = trans_choice('Signed out :count other session.|Signed out :count other sessions.', $total, ['count' => $total]);
    }

    public function render()
    {
        abort_unless((bool) auth()->user()?->can('team.view'), 403);

        $sessions = UserSession::live()
            ->with('user')
            ->orderByDesc('last_active_at')
            ->get()
            ->filter(fn (UserSession $s) => $s->user !== null);

        $people = $sessions->groupBy('user_id')
            ->map(fn ($rows) => [
                'user' => $rows->first()->user,
                'sessions' => $rows,
                'state' => $rows->contains(fn ($s) => UserSessionMonitor::state($s) === 'online') ? 'online' : 'idle',
                'last_active_at' => $rows->first()->last_active_at,
            ])
            ->sortBy(fn ($p) => [$p['state'] === 'online' ? 0 : 1, strtolower((string) $p['user']->name)])
            ->values();

        return view('livewire.user-monitor', [
            'people' => $people,
            'onlineCount' => $people->where('state', 'online')->count(),
            'canManage' => (bool) auth()->user()?->can('users.edit'),
            'currentSessionId' => session()->getId(),
            'actor' => auth()->user(),
        ]);
    }

    private function authorizeManage(): void
    {
        abort_unless((bool) auth()->user()?->can('users.edit'), 403);
    }

    /** A super admin's sessions are only manageable by another super admin. */
    private function canManage(?User $target): bool
    {
        if ($target === null) {
            return false;
        }

        return ! $target->hasRole(User::ROLE_SUPER_ADMIN) || (bool) auth()->user()?->hasRole(User::ROLE_SUPER_ADMIN);
    }
}
