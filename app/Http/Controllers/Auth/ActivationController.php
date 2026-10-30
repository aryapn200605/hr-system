<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Support\AccountRules;
use App\Support\AuthThrottle;
use App\Support\PendingActivation;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use SensitiveParameter;

class ActivationController extends Controller
{
    /**
     * Open an invitation link and move on to a URL without the token. Opening changes nothing
     * but this session, so mail scanners that fetch the link cannot spend the invitation.
     *
     * A logged-in browser is left as it is: the link neither ends that login nor
     * joins it, and the token is not checked, so the page reveals nothing about it.
     */
    public function start(Request $request, #[SensitiveParameter] string $token): Response|RedirectResponse
    {
        if ($request->user()) {
            return response()->view('auth.activation-logged-in', status: 409);
        }

        $invitation = AccountInvitation::findOpenByToken($token);

        if (! $invitation) {
            PendingActivation::clear($request);

            return response()->view('auth.activation-invalid', status: 410);
        }

        PendingActivation::start($request, $invitation);

        return redirect()->route('activation.password');
    }

    /**
     * Show the password form.
     */
    public function createPassword(): View
    {
        return view('auth.activation-password');
    }

    /**
     * Keep the chosen password for the last step.
     */
    public function storePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate(['password' => AccountRules::password()]);

        PendingActivation::rememberPassword($request, $validated['password']);

        return redirect()->route('activation.two-factor');
    }

    /**
     * Show the QR code and manual key for an unconfirmed secret.
     */
    public function createTwoFactor(Request $request): View|RedirectResponse
    {
        if (PendingActivation::passwordHash($request) === null) {
            return redirect()->route('activation.password');
        }

        $secret = PendingActivation::secret($request);

        return view('auth.activation-two-factor', [
            'qrCode' => TwoFactor::qrCodeSvg($request->attributes->get('pending_invitation')->user, $secret),
            'secret' => $secret,
        ]);
    }

    /**
     * Confirm the secret, finish the activation and show the recovery codes.
     *
     * The codes go straight into this response and never into the session, so they appear exactly once.
     */
    public function storeTwoFactor(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $passwordHash = PendingActivation::passwordHash($request);

        if ($passwordHash === null) {
            return redirect()->route('activation.password');
        }

        /** @var AccountInvitation $invitation */
        $invitation = $request->attributes->get('pending_invitation');
        $key = AuthThrottle::twoFactorKey($request, $invitation->user);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['code' => AuthThrottle::message($key)]);
        }

        $secret = PendingActivation::secret($request);
        $timestep = TwoFactor::matchingTimestep($secret, $validated['code']);

        if ($timestep === null) {
            return back()->withErrors(['code' => __('The code is invalid.')]);
        }

        $recoveryCodes = $invitation->user->completeActivation($invitation, $passwordHash, $secret, $timestep);

        PendingActivation::clear($request);
        $request->session()->regenerate(true);

        if ($recoveryCodes === null) {
            return response()->view('auth.activation-invalid', status: 410);
        }

        AuthThrottle::clear($key);

        // Set here because the cache.headers middleware only touches GET and HEAD responses.
        return response()
            ->view('auth.recovery-codes', [
                'recoveryCodes' => $recoveryCodes,
                'continueUrl' => route('login'),
                'continueLabel' => __('I have saved them, go to login'),
                'notice' => __('Your account is active. Logging in needs a new code from your app, so wait for the code you just used to change.'),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
