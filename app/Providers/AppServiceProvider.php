<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Policies\RequestPolicy;
use App\Support\AuthThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    private const REQUESTS_PER_MINUTE = 20;

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

        Gate::policy(LeaveRequest::class, RequestPolicy::class);
        Gate::policy(OvertimeRequest::class, RequestPolicy::class);

        RateLimiter::for('requests', fn (Request $request) => Limit::perMinute(self::REQUESTS_PER_MINUTE)
            ->by((string) $request->user()?->getKey()));

        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(AuthThrottle::ACTIVATION_REQUESTS_PER_MINUTE)
            ->by(AuthThrottle::activationKey($request)));
    }
}
