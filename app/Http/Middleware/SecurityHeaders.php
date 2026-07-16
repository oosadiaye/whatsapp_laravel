<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (audit M7 + M8).
 *
 * Content-Security-Policy: chart.js and the Africa's Talking SDK are now
 * self-hosted under public/vendor (audit M8), so no external script origin is
 * needed — script-src is same-origin. Alpine and Livewire still require
 * 'unsafe-inline'/'unsafe-eval', and a few inline boot scripts (the voice
 * client) need 'unsafe-inline', so those stay for now; tightening to a
 * nonce-based policy is a documented follow-up. Even with those, the policy
 * blocks loading script from any external origin, plugin embedding
 * (object-src none), base-tag injection (base-uri self) and clickjacking
 * (frame-ancestors none).
 *
 * HSTS is emitted only over HTTPS — with TrustProxies configured, secure() is
 * correct behind the load balancer (audit H5).
 *
 * CSP is skipped in the local environment so the Vite HMR dev server
 * (localhost:5173) isn't blocked; the remaining headers apply everywhere.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Voice calls need the mic, so microphone is allowed for same-origin;
        // camera + geolocation are denied outright.
        $response->headers->set('Permissions-Policy', 'microphone=(self), camera=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! app()->environment('local') && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // Alpine/Livewire need eval+inline; all first-party scripts are
            // same-origin (self-hosted vendor libs), so no external host here.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: https:",
            // Reverb WebSocket + the AT WebRTC SDK dial out; keep egress open.
            "connect-src 'self' https: wss: ws:",
            "media-src 'self' blob: data:",
            // Allow the sandboxed email-preview srcdoc iframe (same-origin).
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]);
    }
}
