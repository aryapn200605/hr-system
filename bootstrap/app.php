<?php

use App\Http\Middleware\EnsureFullyAuthenticated;
use App\Http\Middleware\EnsurePendingActivation;
use App\Http\Middleware\EnsurePendingLogin;
use App\Http\Middleware\EnsureReauthenticated;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StartSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'fully.authenticated' => EnsureFullyAuthenticated::class,
            'pending.activation' => EnsurePendingActivation::class,
            'pending.login' => EnsurePendingLogin::class,
            'reauthenticated' => EnsureReauthenticated::class,
        ]);

        $middleware->web(replace: [BaseStartSession::class => StartSession::class]);
        $middleware->web(append: [SetLocale::class]);

        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['identifier', 'code', 'recovery_code']);

        // The auth.session middleware throws, and a Livewire action must get a 401 rather than a redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson() || $request->hasHeader('X-Livewire'),
        );
    })->create();
