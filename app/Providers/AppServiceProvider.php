<?php

declare(strict_types=1);

namespace App\Providers;

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
    private function guardAgainstStrayViteHotFileInProduction(): void
    {
        if ($this->app->environment('production')) {
            Vite::useHotFile(storage_path('framework/vite-disabled-in-production.hot'));
        }
    }
}
