<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind nginx / a load balancer the app only ever sees the proxy's IP
        // and scheme. Trust the proxy so the client's real IP + scheme are
        // restored — required for the webhook source-IP allowlist
        // (AllowedWebhookIps), correct HTTPS detection (secure cookies, HSTS,
        // generated URLs), and IP-keyed login throttling (audit H5).
        //
        // SECURITY (review M1): '*' trusts X-Forwarded-* from ANY client, which
        // is only safe when the app is reachable ONLY through the proxy. If the
        // origin can be hit directly, PIN this to your load balancer's CIDR(s)
        // here so a client can't spoof its IP and defeat the webhook IP allowlist
        // / per-IP rate limits — e.g.  at: ['10.0.0.0/8', '172.16.0.0/12'].
        // (This runs before config/env is loaded, so it must be a literal.)
        $middleware->trustProxies(at: '*');

        // Baseline security headers (CSP/HSTS/etc.) on every web response.
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);

        // Log out users deactivated mid-session on their next request.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureUserIsActive::class);

        $middleware->alias([
            // Optional source-IP allowlist for provider webhooks (empty = off).
            'webhook.allowed-ips' => \App\Http\Middleware\AllowedWebhookIps::class,
            // spatie/laravel-permission middleware aliases — usage:
            //   ->middleware('role:admin')
            //   ->middleware('permission:users.create')
            //   ->middleware('role_or_permission:admin|users.view')
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Meta posts here without CSRF tokens. Excluding only the CSRF check
        // keeps the rest of the web group (SubstituteBindings, session, etc.)
        // so route model binding still resolves {instance}.
        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp/*',
            'webhooks/africastalking/*',
            // Provider bounce/complaint webhooks — authenticated by the URL secret
            // path segment (+ provider signature), not a CSRF token.
            'webhooks/email/*',
            // RFC 8058 one-click unsubscribe POSTs here; it's protected by the
            // signed-URL middleware instead of a CSRF token.
            'email/unsubscribe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
