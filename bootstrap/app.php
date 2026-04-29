<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminOnly::class,
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
