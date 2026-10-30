<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * The state between opening an invitation link and finishing activation.
 *
 * It lives in the session only, without the token. Nothing reaches the
 * user row until User::completeActivation() writes it all at once.
 */
class PendingActivation
{
    private const SESSION_KEY = 'pending_activation';

    private const TTL_SECONDS = 1800;

    /**
     * Start an activation after a valid link, in a fresh session holding no other login state.
     */
    public static function start(Request $request, AccountInvitation $invitation): void
    {
        PendingLogin::clear($request);

        $request->session()->regenerate(true);
        $request->session()->put(self::SESSION_KEY, [
            'invitation_id' => $invitation->getKey(),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);
    }

    /**
     * Get the invitation, read fresh without a lock on every step. Null unless it is still open and
     * its account still pending. User::completeActivation() repeats these checks under a lock.
     */
    public static function invitation(Request $request): ?AccountInvitation
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || $pending['expires_at'] <= now()->getTimestamp()) {
            return null;
        }

        $invitation = AccountInvitation::query()->open()->with('user')->find($pending['invitation_id']);

        return $invitation?->user->status === UserStatus::Pending ? $invitation : null;
    }

    /**
     * Keep the chosen password, hashed and encrypted, until the 2FA step succeeds.
     */
    public static function rememberPassword(Request $request, #[SensitiveParameter] string $password): void
    {
        $request->session()->put(self::SESSION_KEY.'.password', Crypt::encryptString(Hash::make($password)));
    }

    /**
     * Get the hash of the chosen password, if that step is done.
     */
    public static function passwordHash(Request $request): ?string
    {
        $encrypted = $request->session()->get(self::SESSION_KEY.'.password');

        return $encrypted === null ? null : Crypt::decryptString($encrypted);
    }

    /**
     * Get the secret being enrolled.
     */
    public static function secret(Request $request): string
    {
        return TwoFactor::sessionSecret($request, self::SESSION_KEY.'.secret');
    }

    /**
     * Drop the pending state.
     */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
