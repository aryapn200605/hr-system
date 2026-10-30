<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorEnrollmentController;
use App\Http\Controllers\Security\AuthenticatorController;
use App\Http\Controllers\Security\ConfirmationController;
use App\Http\Controllers\Security\IdentityController;
use App\Http\Controllers\Security\PasswordController;
use App\Http\Controllers\Security\RecoveryCodeController;
use App\Http\Middleware\EnsurePendingLogin;
use App\Livewire\Dashboard;
use App\Livewire\Profile\Show as ProfileShow;
use App\Livewire\Security\Index as SecurityIndex;
use App\Livewire\Security\LoginActivity;
use App\Livewire\Security\Sessions;
use App\Livewire\Users\Index as UserIndex;
use App\Livewire\Users\Show as UserShow;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

// Outside the guest group, so a logged-in browser gets an explanation instead of a silent redirect.
Route::get('/activation/{token}', [ActivationController::class, 'start'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware(['throttle:activation', 'cache.headers:no_store;private'])
    ->name('activation.start');

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

    Route::middleware(['throttle:activation', 'pending.activation'])->group(function () {
        Route::get('/activation', [ActivationController::class, 'createPassword'])->name('activation.password');
        Route::post('/activation', [ActivationController::class, 'storePassword'])->name('activation.password.store');
        Route::get('/activation/two-factor', [ActivationController::class, 'createTwoFactor'])->name('activation.two-factor');
        Route::post('/activation/two-factor', [ActivationController::class, 'storeTwoFactor'])->name('activation.two-factor.store');
    });

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'start'])->name('password.reset.start');
});

Route::middleware(['fully.authenticated', 'auth.session'])->group(function () {
    Route::redirect('/', '/dashboard');

    Route::livewire('/dashboard', Dashboard::class)
        ->middleware('can:'.PermissionName::DashboardView->value)
        ->name('dashboard');

    Route::middleware('can:'.PermissionName::UsersView->value)->group(function () {
        Route::livewire('/users', UserIndex::class)->name('users.index');
        Route::livewire('/users/{user}', UserShow::class)->whereNumber('user')->name('users.show');
    });

    // These act only on the logged-in user, so they take no ID and need no permission.
    Route::livewire('/profile', ProfileShow::class)->name('profile.show');
    Route::livewire('/security', SecurityIndex::class)->name('security.index');
    Route::livewire('/security/sessions', Sessions::class)->name('security.sessions');
    Route::livewire('/security/login-history', LoginActivity::class)->name('security.login-history');

    // Controllers, not Livewire: a Livewire snapshot would carry passwords, secrets and codes back and forth.
    Route::middleware('cache.headers:no_store;private')->group(function () {
        Route::get('/security/confirm', [ConfirmationController::class, 'create'])->name('security.confirm');
        Route::post('/security/confirm', [ConfirmationController::class, 'store'])->name('security.confirm.store');
        Route::post('/security/authenticator/cancel', [AuthenticatorController::class, 'destroy'])->name('security.authenticator.cancel');

        Route::middleware('reauthenticated:profile.identity')->group(function () {
            Route::get('/profile/identity', [IdentityController::class, 'edit'])->name('profile.identity');
            Route::post('/profile/identity', [IdentityController::class, 'update'])->name('profile.identity.update');
        });

        Route::middleware('reauthenticated:security.password')->group(function () {
            Route::get('/security/password', [PasswordController::class, 'edit'])->name('security.password');
            Route::post('/security/password', [PasswordController::class, 'update'])->name('security.password.update');
        });

        Route::middleware('reauthenticated:security.recovery-codes')->group(function () {
            Route::get('/security/recovery-codes', [RecoveryCodeController::class, 'create'])->name('security.recovery-codes');
            Route::post('/security/recovery-codes', [RecoveryCodeController::class, 'store'])->name('security.recovery-codes.store');
        });

        Route::middleware('reauthenticated:security.authenticator')->group(function () {
            Route::get('/security/authenticator', [AuthenticatorController::class, 'create'])->name('security.authenticator');
            Route::post('/security/authenticator', [AuthenticatorController::class, 'store'])->name('security.authenticator.store');
        });
    });

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Every Livewire action passes through this one route. Livewire's own route has
    // only the web group, and custom route middleware is not reapplied to its updates.
    Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle));
});
