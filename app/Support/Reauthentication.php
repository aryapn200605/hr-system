<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Proof that the user just repeated their password and second factor, needed before a sensitive action.
 *
 * It lives in this session only, ends after a fixed time however busy the session is, is spent
 * by one completed action, and dies with the password it was checked against.
 */
class Reauthentication
{
    private const SESSION_KEY = 'reauthentication';

    private const INTENDED_KEY = 'reauthentication_intended';

    /**
     * Long enough to set up an authenticator app, and never extended by activity.
     */
    public const TTL_SECONDS = 600;

    /**
     * Record the proof.
     */
    public static function grant(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'password_hash' => PendingLogin::passwordHash($user),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);
    }

    /**
     * Determine whether this session holds a live proof for the user's current password.
     */
    public static function isValid(Request $request, User $user): bool
    {
        $proof = $request->session()->get(self::SESSION_KEY);

        return is_array($proof)
            && $proof['expires_at'] > now()->getTimestamp()
            && hash_equals(PendingLogin::passwordHash($user), $proof['password_hash']);
    }

    /**
     * Use up the proof, so that a repeated request cannot run the action twice.
     */
    public static function spend(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Remember the route to return to. Route names come from route definitions, never from the client.
     */
    public static function intend(Request $request, string $route): void
    {
        $request->session()->put(self::INTENDED_KEY, $route);
    }

    /**
     * Get and forget the route to return to.
     */
    public static function pullIntended(Request $request): ?string
    {
        return $request->session()->pull(self::INTENDED_KEY);
    }
}
