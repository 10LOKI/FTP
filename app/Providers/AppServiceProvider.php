<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
