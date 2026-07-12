<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional source-IP allowlist for the provider webhook endpoints. When
 * voice.webhook_ip_allowlist is set (individual IPs and/or CIDR ranges), any
 * request from outside those ranges is rejected. Empty list = disabled (accept
 * from anywhere), so this is safe to leave on by default.
 *
 * Locking the endpoints to Meta / Africa's Talking published ranges is a
 * cheap, real defense — the webhooks are unauthenticated by nature.
 */
class AllowedWebhookIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = (array) config('voice.webhook_ip_allowlist', []);

        if ($allowlist === []) {
            return $next($request); // allowlist disabled
        }

        if (IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            return $next($request);
        }

        Log::warning('Webhook rejected: source IP not allowlisted', [
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        abort(403);
    }
}
