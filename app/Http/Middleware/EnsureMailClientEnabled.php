<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the per-employee email client behind config('mail_client.enabled') — the
 * whole feature 404s until it's live-verified and switched on (plan B6). Applied
 * to the mailbox route group so nothing is reachable (no dead UI) while off.
 */
class EnsureMailClientEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('mail_client.enabled'), 404);

        return $next($request);
    }
}
