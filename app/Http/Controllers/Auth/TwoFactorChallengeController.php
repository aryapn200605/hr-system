<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\PendingLogin;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    /**
     * Show the challenge form.
     */
    public function create(): View
    {
        return view('auth.two-factor-challenge');
    }

    /**
     * Finish the login with an authenticator code.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        /** @var User $user */
        $user = $request->attributes->get('pending_user');

        if ($response = $this->throttled($request, $user, 'code')) {
            return $response;
        }

        if (! TwoFactor::verify($user, $validated['code'])) {
            LoginHistory::recordFailure($request, LoginResult::FailedTotp, $user);

            return back()->withErrors(['code' => __('The code is invalid.')]);
        }

        PendingLogin::complete($request, $user, 'totp');

        return redirect()->route('dashboard');
    }

    /**
     * Finish the login with a recovery code.
     */
    public function recover(Request $request): RedirectResponse
    {
        $validated = $request->validate(['recovery_code' => ['required', 'string', 'max:64']]);

        /** @var User $user */
        $user = $request->attributes->get('pending_user');

        if ($response = $this->throttled($request, $user, 'recovery_code')) {
            return $response;
        }

        if (! RecoveryCode::consume($user, $validated['recovery_code'])) {
            LoginHistory::recordFailure($request, LoginResult::FailedRecovery, $user);

            return back()->withErrors(['recovery_code' => __('The code is invalid.')]);
        }

        AuditLog::record(AuditEvent::RecoveryCodeUsed, $user, $user);
        PendingLogin::complete($request, $user, 'recovery_code');

        return redirect()->route('dashboard');
    }

    /**
     * Count the attempt in the bucket shared by TOTP and recovery codes.
     */
    private function throttled(Request $request, User $user, string $field): ?RedirectResponse
    {
        $key = AuthThrottle::twoFactorKey($request, $user);

        return AuthThrottle::exceeded($key)
            ? back()->withErrors([$field => AuthThrottle::message($key)])
            : null;
    }
}
