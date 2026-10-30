<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\PendingLogin;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TwoFactorEnrollmentController extends Controller
{
    /**
     * Show the QR code and manual key for an unconfirmed secret.
     */
    public function create(Request $request): View
    {
        $secret = PendingLogin::enrollmentSecret($request);

        return view('auth.two-factor-enrollment', [
            'qrCode' => TwoFactor::qrCodeSvg($request->attributes->get('pending_user'), $secret),
            'secret' => $secret,
        ]);
    }

    /**
     * Confirm the secret, log the user in and show the recovery codes.
     *
     * The codes go straight into this response and never into the session, so they appear exactly once.
     */
    public function store(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        /** @var User $user */
        $user = $request->attributes->get('pending_user');
        $key = AuthThrottle::twoFactorKey($request, $user);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['code' => AuthThrottle::message($key)]);
        }

        $secret = PendingLogin::enrollmentSecret($request);
        $timestep = TwoFactor::matchingTimestep($secret, $validated['code']);

        if ($timestep === null) {
            LoginHistory::recordFailure($request, LoginResult::FailedTotp, $user);

            return back()->withErrors(['code' => __('The code is invalid.')]);
        }

        $recoveryCodes = $user->completeTwoFactorEnrollment($secret, $timestep);

        if ($recoveryCodes === null) {
            PendingLogin::clear($request);

            return redirect()->route('login');
        }

        PendingLogin::complete($request, $user->fresh(), 'enrollment');

        // Set here because the cache.headers middleware only touches GET and HEAD responses.
        return response()
            ->view('auth.recovery-codes', ['recoveryCodes' => $recoveryCodes])
            ->header('Cache-Control', 'no-store, private');
    }
}
