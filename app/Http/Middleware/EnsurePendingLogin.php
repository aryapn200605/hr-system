<?php

namespace App\Http\Middleware;

use App\Support\PendingLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only with a valid pending login, on the stage that fits the account.
 */
class EnsurePendingLogin
{
    public const CHALLENGE = 'challenge';

    public const ENROLLMENT = 'enrollment';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $stage): Response
    {
        $user = PendingLogin::user($request);

        if (! $user) {
            PendingLogin::clear($request);

            return redirect()->route('login');
        }

        $required = $user->hasCompletedTwoFactorEnrollment() ? self::CHALLENGE : self::ENROLLMENT;

        if ($stage !== $required) {
            return redirect()->route('two-factor.'.$required);
        }

        $request->attributes->set('pending_user', $user);

        return $next($request);
    }
}
