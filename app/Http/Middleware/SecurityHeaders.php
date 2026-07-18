<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (audit M7 + M8).
 *
 * Content-Security-Policy: chart.js and the Africa's Talking SDK are now
 * self-hosted under public/vendor (audit M8), so no external script origin is
 * needed. script-src is nonce-based — a per-request nonce (Vite::useCspNonce)
 * is carried by @vite, Livewire (which reads Vite::cspNonce()), and our inline
 * <script> tags, so 'unsafe-inline' is dropped: an injected inline script won't
 * execute without the nonce. 'unsafe-eval' remains because Alpine 3's standard
 * build evaluates x-data/x-on expressions via new Function() (removing it needs
 * the Alpine CSP build — a separate, larger change). style-src keeps
 * 'unsafe-inline' because inline style="" attributes can't use nonces. The
 * policy also blocks external script origins, plugin embedding (object-src
 * none), base-tag injection (base-uri self) and clickjacking (frame-ancestors
 * none).
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
        // Generate the per-request CSP nonce BEFORE the view renders so @vite,
        // Livewire, and our inline <script nonce="..."> tags all emit the same
        // value that the script-src directive below trusts.
        Vite::useCspNonce();

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
        // Same per-request nonce that @vite/Livewire/our inline scripts emit.
        $nonce = Vite::cspNonce();

        return implode('; ', [
            "default-src 'self'",
            // Nonce-based: inline scripts must carry this nonce, so an injected
            // <script> can't run. 'unsafe-eval' stays for Alpine's expression
            // evaluation (see class docblock). All first-party scripts are
            // same-origin (self-hosted vendor libs), so no external host.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: https:",
            // No broad https: — fetch/XHR is same-origin (Livewire), so an
            // injected script can't beacon data to an arbitrary host via fetch
            // (review M2). WebSocket egress (Reverb; AT WebRTC signalling when
            // voice is enabled) stays open via wss/ws. If live AT voice needs a
            // specific https endpoint, add it here explicitly.
            "connect-src 'self' wss: ws:",
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
