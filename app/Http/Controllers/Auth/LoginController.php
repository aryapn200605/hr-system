<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\PendingLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Check the password and start a pending login. Nobody is logged in here.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $throttleKey = AuthThrottle::loginKey($request, $credentials['identifier']);

        if (AuthThrottle::exceeded($throttleKey)) {
            return back()->withErrors(['identifier' => AuthThrottle::message($throttleKey)]);
        }

        $user = $this->findUser($credentials['identifier']);

        // Exactly one hash check on every path: a missing account or a null password uses the dummy hash.
        $passwordMatches = Hash::check($credentials['password'], $user?->password ?? $this->dummyHash());

        if (! $user || ! $passwordMatches) {
            return $this->failed($request, LoginResult::FailedCredentials, $user);
        }

        if ($user->status !== UserStatus::Active) {
            return $this->failed($request, LoginResult::FailedAccountStatus, $user);
        }

        AuthThrottle::clear($throttleKey);
        PendingLogin::start($request, $user);

        return redirect()->route(
            $user->hasCompletedTwoFactorEnrollment() ? 'two-factor.challenge' : 'two-factor.enrollment',
        );
    }

    /**
     * Abandon a pending login.
     */
    public function cancel(Request $request): RedirectResponse
    {
        PendingLogin::clear($request);

        return redirect()->route('login');
    }

    /**
     * Log out and end the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Find the account. Usernames cannot contain "@", so it always marks an email.
     */
    private function findUser(string $identifier): ?User
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';

        return User::query()->where($column, mb_strtolower($identifier))->first();
    }

    /**
     * A hash with the application's cost that matches nothing. Cached so that
     * the unknown-account path does not pay for an extra hash.
     */
    private function dummyHash(): string
    {
        return Cache::rememberForever(
            'auth.dummy-hash.'.config('hashing.bcrypt.rounds'),
            fn () => Hash::make(Str::random(40)),
        );
    }

    /**
     * Record a failure and answer with the same message for every cause.
     */
    private function failed(Request $request, LoginResult $result, ?User $user): RedirectResponse
    {
        LoginHistory::recordFailure($request, $result, $user);

        return back()->withErrors(['identifier' => __('auth.failed')]);
    }
}
