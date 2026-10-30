<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limits for the login stages. Keys are hashed so the cache never stores a typed identifier.
 */
class AuthThrottle
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * The password stage bucket, per identifier and IP.
     */
    public static function loginKey(Request $request, string $identifier): string
    {
        return 'login:'.hash('sha256', mb_strtolower($identifier).'|'.$request->ip());
    }

    /**
     * The bucket shared by TOTP, recovery codes and enrollment confirmation, per user and IP.
     */
    public static function twoFactorKey(Request $request, User $user): string
    {
        return 'two-factor:'.hash('sha256', $user->getKey().'|'.$request->ip());
    }

    /**
     * Count an attempt and report whether it is over the limit. The hit comes before
     * verification and its result is the check, so parallel requests cannot slip past the limit.
     */
    public static function exceeded(string $key): bool
    {
        return RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS;
    }

    /**
     * Forget the attempts in a bucket.
     */
    public static function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    /**
     * The message shown while a bucket is over its limit.
     */
    public static function message(string $key): string
    {
        return __('Too many attempts. Please try again in :seconds seconds.', [
            'seconds' => RateLimiter::availableIn($key),
        ]);
    }
}
