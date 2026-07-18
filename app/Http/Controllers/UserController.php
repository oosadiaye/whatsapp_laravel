<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Admin-only user management.
 *
 * Permission gates (enforced via routes/middleware):
 *   - users.view   → index, show
 *   - users.create → create, store
 *   - users.edit   → edit, update, toggleActive
 *   - users.delete → destroy
 *
 * Self-edit guards prevent an admin from accidentally locking themselves out:
 *   - Cannot demote your own role
 *   - Cannot deactivate yourself
 *   - Cannot delete yourself
 *
 * Privilege-boundary guards (Production-Audit blocker B2) stop a non-super_admin
 * (i.e. an `admin`, who keeps `users.edit`) from escalating:
 *   - Only a super_admin may grant/edit the super_admin role.
 *   - A non-super_admin may not edit an existing super_admin at all.
 *   - Setting a *different* user's password requires the actor to confirm
 *     their own current password.
 */
class UserController extends Controller
{
    public function index(): View
    {
        $users = User::with('roles')->orderBy('name')->paginate(25);

        return view('users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('users.create', [
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // Server-side restrict to roles the actor is allowed to grant — a
            // non-super_admin can never assign super_admin. (Reaching store()
            // already requires users.create, which only super_admin has, so this
            // is defense-in-depth against future permission changes.)
            'role' => ['required', 'string', Rule::in($this->assignableRoleNames())],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            // Keep the denormalized `role` column in sync — some rosters query
            // it directly (e.g. team/wallboard `where('role', ROLE_AGENT)`).
            'role' => in_array($validated['role'], [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true) ? 'admin' : 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        return redirect()
            ->route('users.index')
            ->with('success', "User {$user->email} created with role {$validated['role']}.");
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
            'currentRole' => $user->roles->first()?->name,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Privilege boundary (B2): a non-super_admin may neither edit an existing
        // super_admin nor grant the super_admin role. Checked before validation
        // so an admin can't probe field shape or partially apply changes.
        if ($reason = $this->forbiddenTargetReason($user, (string) $request->input('role'))) {
            return redirect()->back()->with('error', $reason);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in($this->assignableRoleNames())],
        ];

        // Setting a *different* user's password is a sensitive action (account
        // takeover from a borrowed/unlocked admin session). Require the acting
        // user to confirm their own current password. Changing your own password
        // is exempt — you already authenticated as yourself.
        if ($request->filled('password') && $user->id !== auth()->id()) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $validated = $request->validate($rules);

        // Guard: prevent self-demotion.
        if ($user->id === auth()->id() && $validated['role'] !== $user->roles->first()?->name) {
            return redirect()
                ->back()
                ->with('error', 'You cannot change your own role. Ask another admin to do it.');
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => in_array($validated['role'], [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true) ? 'admin' : 'user',
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $user->syncRoles([$validated['role']]);

        return redirect()
            ->route('users.index')
            ->with('success', "User {$user->email} updated.");
    }

    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return redirect()
                ->back()
                ->with('error', 'You cannot deactivate yourself.');
        }

        // Same privilege boundary as update() (B2): a non-super_admin must not be
        // able to deactivate a super_admin — otherwise an `admin` (who holds
        // users.edit) could force-logout and lock out a super_admin via
        // EnsureUserIsActive + the LoginRequest active check.
        if ($reason = $this->forbiddenTargetReason($user, $user->roles->first()?->name ?? '')) {
            return redirect()->back()->with('error', $reason);
        }

        $user->update(['is_active' => ! $user->is_active]);
        $action = $user->is_active ? 'activated' : 'deactivated';

        return redirect()
            ->back()
            ->with('success', "User {$user->email} {$action}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return redirect()
                ->back()
                ->with('error', 'You cannot delete yourself.');
        }

        $email = $user->email;
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', "User {$email} deleted.");
    }

    /**
     * Role names the acting user is permitted to grant. Only a super_admin may
     * assign the super_admin role; everyone else (i.e. `admin`) gets every role
     * except super_admin. Used both as the edit/create dropdown source and as
     * the server-side `Rule::in` allowlist, so the two can't drift.
     *
     * @return list<string>
     */
    private function assignableRoleNames(): array
    {
        $names = Role::orderBy('name')->pluck('name');

        if (auth()->user()->hasRole(User::ROLE_SUPER_ADMIN)) {
            return $names->all();
        }

        return $names->reject(fn (string $name): bool => $name === User::ROLE_SUPER_ADMIN)
            ->values()
            ->all();
    }

    /**
     * The Role models corresponding to {@see assignableRoleNames()}, for the
     * view dropdowns.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        return Role::whereIn('name', $this->assignableRoleNames())
            ->orderBy('name')
            ->get();
    }

    /**
     * Returns a human-readable reason the acting user may not manage this target
     * with this requested role, or null if the action is permitted. A super_admin
     * is unrestricted; a non-super_admin may neither touch an existing super_admin
     * nor grant the super_admin role.
     */
    private function forbiddenTargetReason(User $target, string $requestedRole): ?string
    {
        if (auth()->user()->hasRole(User::ROLE_SUPER_ADMIN)) {
            return null;
        }

        if ($target->hasRole(User::ROLE_SUPER_ADMIN)) {
            return 'You do not have permission to edit a super administrator.';
        }

        if ($requestedRole === User::ROLE_SUPER_ADMIN) {
            return 'You do not have permission to assign the super administrator role.';
        }

        return null;
    }
}
