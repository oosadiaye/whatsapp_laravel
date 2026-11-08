<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lets a manager decide, person by person, who only sees the records they created.
 *
 * Gated by `users.scope`, which a manager holds without any user-management rights. The page only
 * lists, and only changes, people in the `user` and `agent` roles: nobody can restrict themselves,
 * a peer or someone senior.
 */
class TeamAccessController extends Controller
{
    /** Roles a manager may confine. */
    private const MANAGEABLE_ROLES = [User::ROLE_USER, User::ROLE_AGENT];

    public function index(): View
    {
        $people = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::MANAGEABLE_ROLES))
            ->with('roles')
            ->orderBy('name')
            ->get();

        return view('team-access.index', ['people' => $people]);
    }

    public function update(Request $request, User $user, AuditService $audit): RedirectResponse
    {
        abort_if($user->is(auth()->user()), 403, 'You cannot change your own access.');
        abort_unless($user->hasAnyRole(self::MANAGEABLE_ROLES), 403, 'You can only change access for users and agents.');

        $restrict = $request->boolean('restrict_to_own');
        $user->update(['restrict_to_own' => $restrict]);

        $audit->log('user.access_scope', 'user', $user->id, ['restrict_to_own' => $restrict]);

        return back()->with('success', $restrict
            ? "{$user->name} now only sees records they created."
            : "{$user->name} can now see everyone's records.");
    }
}
