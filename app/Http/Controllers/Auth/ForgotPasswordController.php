<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\MailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class ForgotPasswordController extends Controller
{
    /**
     * Show the forgot password form.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Answer the same way for every address, then do the work after the response.
     *
     * The status of an account must not show in the response or its timing, so nothing
     * here looks the account up. That also means this response cannot report a transport
     * failure, so its wording promises nothing and sendLink() records the failure instead.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $key = AuthThrottle::forgotPasswordKey($request, $validated['email']);

        if (AuthThrottle::exceeded($key)) {
            return back()->withErrors(['email' => AuthThrottle::message($key)]);
        }

        defer(fn () => $this->sendLink($validated['email']));

        $response = back()->with('status', __('If this address can reset a password, a link is on its way. It may take a few minutes.'));

        // Lets the browser finish at the end of the body while the deferred work runs on mod_php.
        return $response->header('Content-Length', (string) strlen($response->getContent()));
    }

    /**
     * Send a link to an active account. Every other outcome, including the broker's own throttle, is silent.
     */
    private function sendLink(string $email): void
    {
        Password::sendResetLink(
            ['email' => mb_strtolower($email), 'status' => UserStatus::Active->value],
            function (User $user, string $token) {
                if (MailDelivery::send($user->email, new PasswordResetMail($user->name, $token))) {
                    return;
                }

                // Without this the broker's throttle would block a retry for a link that never arrived.
                Password::deleteToken($user);

                AuditLog::record(AuditEvent::PasswordResetDeliveryFailed, subject: $user);
            },
        );
    }
}
