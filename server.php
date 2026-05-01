<?php

/**
 * Laravel "server.php" — a router script for PHP's built-in dev server.
 *
 * Run via:
 *   php -S 127.0.0.1:8000 -t public server.php
 *
 * Behavior:
 *   - If the request URI maps to a real file inside public/ (CSS, JS, images,
 *     compiled assets in public/build/, favicon, robots.txt, etc.), return
 *     `false` so PHP serves it directly with the correct Content-Type.
 *   - Otherwise, forward the request to public/index.php (Laravel's front
 *     controller), which then dispatches through routes/middleware as normal.
 *
 * Why this exists: when you pass a router script to `php -S`, PHP routes
 * EVERY request through it — including static assets. If we always required
 * public/index.php, asset requests would be intercepted by Laravel's auth
 * middleware and 302-redirected to /login, breaking CSS/JS loading entirely.
 *
 * This file is the standard Laravel pattern (shipped historically as
 * `artisan serve`'s underlying dispatcher). Re-introduced here because
 * `artisan serve` segfaults on PHP 8.2 + Windows in this environment.
 */

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// If the requested file exists in public/, let the dev server serve it.
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
