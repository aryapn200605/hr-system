<?php

namespace App\Http\Controllers\Security;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\Reauthentication;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use SensitiveParameter;

class ConfirmationController extends Controller
{
    /**
     * Show the form that asks for the password and a second factor again.
     */
    public function create(): View
    {
        return view('security.confirm');
    }

    /**
     * Grant a proof for the next sensitive action. The session keeps its ID: a request in
     * flight that regenerates it could bring back a session the user just ended elsewhere.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'string', 'digits:6'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $key = AuthThrottle::twoFactorKey($request, $user);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['password' => AuthThrottle::message($key)]);
        }

        $method = $this->verify($user, $validated);

        if ($method === null) {
            AuditLog::record(AuditEvent::ReauthenticationFailed, $user, $user);

            return back()->withErrors(['password' => __('The password or code is invalid.')]);
        }

        AuthThrottle::clear($key);
        Reauthentication::grant($request, $user);
        AuditLog::record(AuditEvent::ReauthenticationSucceeded, $user, $user, ['method' => $method]);

        return redirect()->route(Reauthentication::pullIntended($request) ?? 'security.index');
    }

    /**
     * Check the password first, so that a wrong one spends neither a code nor a timestep.
     * Return the second factor that was used, or null when anything failed.
     *
     * @param  array{password: string, code?: ?string, recovery_code?: ?string}  $input
     */
    private function verify(User $user, #[SensitiveParameter] array $input): ?string
    {
        if (! Hash::check($input['password'], $user->password)) {
            return null;
        }

        if (filled($input['recovery_code'] ?? null)) {
            if (! RecoveryCode::consume($user, $input['recovery_code'])) {
                return null;
            }

            AuditLog::record(AuditEvent::RecoveryCodeUsed, $user, $user);

            return 'recovery_code';
        }

        return TwoFactor::verify($user, $input['code']) ? 'totp' : null;
    }
}
