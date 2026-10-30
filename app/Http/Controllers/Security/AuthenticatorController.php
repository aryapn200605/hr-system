<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Support\AuthThrottle;
use App\Support\Reauthentication;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AuthenticatorController extends Controller
{
    /**
     * Stays in the session past the proof's expiry, so that a code already scanned keeps working.
     */
    private const SESSION_KEY = 'reconfigure_two_factor_secret';

    /**
     * Show the QR code and manual key of a new, unconfirmed secret. The stored one is never shown.
     */
    public function create(Request $request): View
    {
        $secret = TwoFactor::sessionSecret($request, self::SESSION_KEY);

        return view('security.authenticator', [
            'qrCode' => TwoFactor::qrCodeSvg($request->user(), $secret),
            'secret' => $secret,
        ]);
    }

    /**
     * Swap in the new secret once a code from it is valid, and show the new recovery codes.
     *
     * The code is checked with no last timestep, because the stored one belongs to the old secret.
     * A wrong code changes nothing, so the old authenticator keeps working until the swap.
     */
    public function store(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $user = $request->user();
        $key = AuthThrottle::twoFactorKey($request, $user);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['code' => AuthThrottle::message($key)]);
        }

        $secret = TwoFactor::sessionSecret($request, self::SESSION_KEY);
        $timestep = TwoFactor::matchingTimestep($secret, $validated['code']);

        if ($timestep === null) {
            return back()->withErrors(['code' => __('The code is invalid.')]);
        }

        $recoveryCodes = $user->reconfigureTwoFactor($secret, $timestep, $request->session()->getId());

        if ($recoveryCodes === null) {
            return redirect()->route('security.index');
        }

        $request->session()->forget(self::SESSION_KEY);
        Reauthentication::spend($request);
        AuthThrottle::clear($key);

        // Set here because the cache.headers middleware only touches GET and HEAD responses.
        return response()
            ->view('auth.recovery-codes', [
                'recoveryCodes' => $recoveryCodes,
                'notice' => 'Your new authenticator is active. Your previous recovery codes and other sessions have ended.',
                'continueUrl' => route('security.index'),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Throw the unconfirmed secret away.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('security.index');
    }
}
