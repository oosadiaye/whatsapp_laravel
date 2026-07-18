<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->guardAgainstStrayViteHotFileInProduction();
        $this->configureTrustedProxies();
        $this->registerObservability();

        // Setting has a process-static read cache (audit L12). PHP-FPM resets
        // statics per request, but a long-lived queue worker keeps them across
        // jobs — so a setting changed in the web process would be served stale by
        // the worker until it recycled. Flush before each job to bound staleness
        // to a single job.
        Queue::before(fn () => Setting::flushCache());
    }

    /**
     * Observability wiring (audit L10).
     *
     * 1. Deep health check: the `/up` route dispatches DiagnosingHealth. Probe
     *    the primary datastore so a throw makes `/up` return non-200 — an uptime
     *    monitor then reflects real availability, not just "PHP booted" (the
     *    default `/up` returns 200 even with the database unreachable).
     * 2. Queue backlog: `queue:monitor` (scheduled in routes/console.php) fires
     *    QueueBusy when a queue exceeds its threshold. Log it so a backing-up
     *    send/import queue is visible + alertable without watching Horizon.
     */
    private function registerObservability(): void
    {
        Event::listen(function (DiagnosingHealth $event): void {
            // Cheap liveness of the DB connection — throws if unreachable.
            DB::connection()->getPdo();
        });

        Event::listen(function (QueueBusy $event): void {
            Log::warning('Queue backlog exceeded threshold', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
    }

    /**
     * Defense against a stale `public/hot` file in production.
     *
     * When `npm run dev` runs anywhere, it writes `public/hot` containing the
     * Vite dev server URL. Laravel's @vite() Blade directive checks that path:
     * if the file exists, it emits <script src="http://127.0.0.1:5173/...">
     * tags — which the browser blocks via CORS when served from a real domain.
     *
     * This redirects Laravel's hot-file lookup to a path under storage/ that
     * Vite never writes to, so even if `public/hot` is sitting right there
     * (misconfigured deploy, manual upload, leftover from someone running
     * `npm run dev` on the server), production traffic is unaffected.
     * Manifest mode (public/build/manifest.json) wins, every time.
     *
     * Local dev is unaffected — APP_ENV=local skips this guard so HMR works.
     */
    /**
     * Configure TrustProxies from config (review M1). Set here — not in
     * bootstrap/app.php's withMiddleware closure — because that closure runs
     * before config/env is loaded; the middleware reads this static
     * (TrustProxies::$alwaysTrustProxies) at request time, well after boot().
     *
     * SECURITY: '*' trusts X-Forwarded-* from ANY client, safe only when the app
     * is reachable ONLY through the proxy. If the origin can be hit directly, set
     * TRUSTED_PROXIES to your LB's CIDR(s) so a client can't spoof its IP and
     * defeat the webhook IP allowlist / per-IP rate limits — restoring the real
     * client IP + scheme behind nginx is what H5 needs.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies', '*');

        TrustProxies::at($proxies === '*' || blank($proxies)
            ? '*'
            : array_map('trim', explode(',', (string) $proxies)));
    }

    private function guardAgainstStrayViteHotFileInProduction(): void
    {
        if ($this->app->environment('production')) {
            Vite::useHotFile(storage_path('framework/vite-disabled-in-production.hot'));
        }
    }
}
