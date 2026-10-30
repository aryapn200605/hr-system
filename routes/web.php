<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorEnrollmentController;
use App\Http\Middleware\EnsurePendingLogin;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

Route::middleware(['guest', 'cache.headers:no_store;private'])->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::post('/two-factor/cancel', [LoginController::class, 'cancel'])->name('two-factor.cancel');

    Route::middleware('pending.login:'.EnsurePendingLogin::CHALLENGE)->group(function () {
        Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
        Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');
        Route::post('/two-factor/recovery', [TwoFactorChallengeController::class, 'recover'])->name('two-factor.recovery.store');
    });

    Route::middleware('pending.login:'.EnsurePendingLogin::ENROLLMENT)->group(function () {
        Route::get('/two-factor/enrollment', [TwoFactorEnrollmentController::class, 'create'])->name('two-factor.enrollment');
        Route::post('/two-factor/enrollment', [TwoFactorEnrollmentController::class, 'store'])->name('two-factor.enrollment.store');
    });
});

Route::middleware('fully.authenticated')->group(function () {
    Route::redirect('/', '/dashboard');

    Route::livewire('/dashboard', Dashboard::class)
        ->middleware('can:'.PermissionName::DashboardView->value)
        ->name('dashboard');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Every Livewire action passes through this one route. Livewire's own route has
    // only the web group, and custom route middleware is not reapplied to its updates.
    Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle));
});
