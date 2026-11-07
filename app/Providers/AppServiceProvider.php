<?php

namespace App\Providers;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AccessPermissionCatalog;
use App\Services\AuditContext;
use App\Services\EffectivePermissions;
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
        $this->app->scoped(AuditContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (AccessPermissionCatalog::LABELS as $code => $label) {
            Gate::define($code, fn (User $user): bool => app(EffectivePermissions::class)->allows($user, $code));
        }
        RateLimiter::for('customer-commerce', function (Request $request): Limit {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $readOnly = $request->isMethod('GET') || $request->isMethod('HEAD');

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

        RateLimiter::for('employee-login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return [
                Limit::perMinute(5)->by('employee-login:'.hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(30)->by('employee-login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('temporary-password', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('temporary-password:'.($request->user()?->id ?? $request->ip())));

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
