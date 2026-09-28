<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? strtolower(trim($email)) : '';

            return [
                Limit::perMinute(config('inventory.login_ip_rate_limit'))->by('login-ip:'.$request->ip()),
                Limit::perMinute(config('inventory.login_rate_limit'))->by('login-email:'.$request->ip().':'.hash('sha256', $email)),
            ];
        });
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('inventory.api_rate_limit'))->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
