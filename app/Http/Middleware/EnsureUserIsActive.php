<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out any authenticated user whose account has been deactivated
 * (is_active = false) mid-session. Without this, UserController::toggleActive
 * only affects future round-robin/assignment eligibility — a deactivated user
 * keeps their live session and full permissions until they voluntarily log out.
 *
 * Appended to the "web" group so it runs on every authenticated web request;
 * guests (no resolved user) pass straight through.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Contact an administrator.',
            ]);
        }

        return $next($request);
    }
}
