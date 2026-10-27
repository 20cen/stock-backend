<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Chaque code de permission est une habileté Gate (ex. 'can:users.manage').
        Gate::before(function (User $user, string $ability) {
            return Permission::tryFrom($ability) ? $user->hasPermission($ability) : null;
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('username')).'|'.$request->ip());
        });
    }
}
