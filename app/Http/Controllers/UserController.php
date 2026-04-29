<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            // Mirror to legacy column for AdminOnly middleware compat.
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
            'roles' => Role::orderBy('name')->get(),
            'currentRole' => $user->roles->first()?->name,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

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
}
