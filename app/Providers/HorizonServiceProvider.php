<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Role-based, not an email allowlist. The empty allowlist previously
        // 403'd EVERYONE in production (Horizon's dashboard was unreachable);
        // super_admins/admins are the operators who need it.
        Gate::define('viewHorizon', function ($user = null): bool {
            return $user !== null
                && method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole([\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN]);
        });
    }
}
