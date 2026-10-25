<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\UserSessionMonitor;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds the user monitor and enforces "log out" from it: records this browser
 * session's activity, and signs the browser out if an admin revoked it.
 *
 * Fails OPEN: if tracking itself errors (e.g. the table isn't migrated yet during
 * a deploy), the request proceeds — the monitor must never take the site down.
 */
class TrackUserSession
{
    public function __construct(private readonly UserSessionMonitor $monitor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        try {
            $stillValid = $this->monitor->record($request, $user);
        } catch (QueryException $e) {
            Log::warning('User session tracking failed; continuing', ['error' => $e->getMessage()]);

            return $next($request);
        }

        if ($stillValid) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Livewire/JSON callers cannot follow a login redirect; 419 makes Livewire
        // offer a page refresh, which lands on the login screen.
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(419, 'You were signed out by an administrator.');
        }

        return redirect()->route('login')->withErrors([
            'email' => 'You were signed out by an administrator. Please sign in again.',
        ]);
    }
}
