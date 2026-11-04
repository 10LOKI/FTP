<?php

namespace App\Providers;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('customer-commerce', function (Request $request): Limit {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $readOnly = $request->isMethod('GET');

            return Limit::perMinute($readOnly ? 120 : 30)
                ->by('customer-commerce:'.($readOnly ? 'read:' : 'write:').$actor);
        });

        Gate::define('ORDER_SUBMIT_OWN', fn (User $user): bool => CustomerProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('approval', 'APPROUVE')->exists());

        Gate::define('CART_MANAGE_OWN', fn (User $user): bool => CustomerProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('approval', 'APPROUVE')->exists());

        Gate::define('catalogue-view-sale-price', fn (User $user): bool => CustomerProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('approval', 'APPROUVE')->exists());

        RateLimiter::for('customer-login', function (Request $request): array {
            $identifier = $request->input('identifier');
            $identifier = is_string($identifier) ? Str::lower($identifier) : '';

            return [
                Limit::perMinute(5)->by('customer-login:'.hash('sha256', $identifier.'|'.$request->ip())),
                Limit::perMinute(30)->by('customer-login-ip:'.$request->ip()),
            ];
        });
    }
}
