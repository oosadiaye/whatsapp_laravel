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
        // NOTE: TrustProxies is configured from config('app.trusted_proxies') in
        // AppServiceProvider::boot() (via TrustProxies::at()), because this
        // closure runs before config/env is loaded. Set TRUSTED_PROXIES to pin
        // trust to your LB's CIDR(s) — see the SECURITY note there (review M1).

        // Baseline security headers (CSP/HSTS/etc.) on every web response.
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);

        // Log out users deactivated mid-session on their next request.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureUserIsActive::class);

        $middleware->alias([
            // Optional source-IP allowlist for provider webhooks (empty = off).
            'webhook.allowed-ips' => \App\Http\Middleware\AllowedWebhookIps::class,
            // Gates the per-employee email client behind config('mail_client.enabled').
            'mailbox.enabled' => \App\Http\Middleware\EnsureMailClientEnabled::class,
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
