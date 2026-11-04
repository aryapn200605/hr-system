<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Policies\RequestPolicy;
use App\Support\AuthThrottle;
use App\Translation\DatabaseLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(LeaveRequest::class, RequestPolicy::class);
        Gate::policy(OvertimeRequest::class, RequestPolicy::class);

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());

        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(AuthThrottle::ACTIVATION_REQUESTS_PER_MINUTE)
            ->by(AuthThrottle::activationKey($request)));

        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(config('requests.exports_per_minute'))
            ->by('exports:'.$request->user()?->getKey()));
    }
}
