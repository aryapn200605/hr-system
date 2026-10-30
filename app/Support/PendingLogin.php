<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/**
 * The state between a valid password and a valid second factor.
 *
 * It lives in the session only and the guard stays empty. complete() is the only place
 * in the application that logs a user in.
 */
class PendingLogin
{
    private const SESSION_KEY = 'pending_login';

    private const CHALLENGE_TTL_SECONDS = 300;

    /**
     * Longer than the challenge, because the user may need to install an authenticator app first.
     */
    private const ENROLLMENT_TTL_SECONDS = 600;

    /**
     * Start a pending login after a valid password.
     */
    public static function start(Request $request, User $user): void
    {
        $ttl = $user->hasCompletedTwoFactorEnrollment()
            ? self::CHALLENGE_TTL_SECONDS
            : self::ENROLLMENT_TTL_SECONDS;

        $request->session()->regenerate(true);
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ]);
    }

    /**
     * Get the pending user, read fresh from the database, unless the state expired or the account is no longer active.
     */
    public static function user(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || $pending['expires_at'] <= now()->getTimestamp()) {
            return null;
        }

        $user = User::find($pending['user_id']);

        return $user?->status === UserStatus::Active ? $user : null;
    }

    /**
     * Get the secret being enrolled, kept encrypted in the session so that
     * users.two_factor_secret is only ever set for a confirmed enrollment.
     */
    public static function enrollmentSecret(Request $request): string
    {
        $key = self::SESSION_KEY.'.secret';

        if (! $request->session()->has($key)) {
            $request->session()->put($key, Crypt::encryptString(TwoFactor::generateSecret()));
        }

        return Crypt::decryptString($request->session()->get($key));
    }

    /**
     * Drop the pending state.
     */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Log the user in after a valid second factor.
     */
    public static function complete(Request $request, User $user, string $method): void
    {
        static::clear($request);

        Auth::login($user);
        $request->session()->regenerate(true);

        $user->forceFill(['last_login_at' => now()])->save();

        AuthThrottle::clear(AuthThrottle::twoFactorKey($request, $user));
        LoginHistory::recordSuccess($request, $user, $method);
    }
}
