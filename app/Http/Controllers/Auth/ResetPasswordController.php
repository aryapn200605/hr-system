<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccountRules;
use App\Support\AuthThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use SensitiveParameter;

class ResetPasswordController extends Controller
{
    private const SESSION_KEY = 'password_reset_token';

    /**
     * Keep the token from the link in the session and move on to a URL without it.
     */
    public function start(Request $request, #[SensitiveParameter] string $token): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, Crypt::encryptString($token));

        return redirect()->route('password.reset');
    }

    /**
     * Show the reset form.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('password.request');
        }

        return view('auth.reset-password');
    }

    /**
     * Reset the password and send the user to the login page. Nobody is logged in
     * here, so the second factor still stands between a reset and the application.
     *
     * Unknown, pending and suspended accounts and bad tokens all give the same answer.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => AccountRules::password(),
        ]);

        if (! $request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('password.request');
        }

        $key = AuthThrottle::resetPasswordKey($request);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['email' => AuthThrottle::message($key)]);
        }

        $status = Password::reset(
            [
                'email' => mb_strtolower($validated['email']),
                'status' => UserStatus::Active->value,
                'password' => $validated['password'],
                'token' => Crypt::decryptString($request->session()->get(self::SESSION_KEY)),
            ],
            fn (User $user, #[SensitiveParameter] string $password) => $user->resetPassword($password),
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __('This link is invalid or has expired, or the email does not match it.')]);
        }

        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate(true);

        return redirect()->route('login')->with('status', __('Your password has been changed. Please log in.'));
    }
}
