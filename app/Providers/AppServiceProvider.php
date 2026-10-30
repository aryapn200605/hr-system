<?php

namespace App\Providers;

use App\Support\AuthThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());

        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(AuthThrottle::ACTIVATION_REQUESTS_PER_MINUTE)
            ->by(AuthThrottle::activationKey($request)));
    }
}
