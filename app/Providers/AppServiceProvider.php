<?php

namespace App\Providers;

use App\Support\AuthThrottle;
use App\Translation\DatabaseLoader;
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
        // Texts come from the translations table. An extender survives the deferred
        // TranslationServiceProvider binding its own file loader later.
        $this->app->extend('translation.loader', fn () => new DatabaseLoader);
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
