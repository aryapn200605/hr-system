<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A user's own rows in the sessions table.
 *
 * A session ID is a credential, so the page only ever holds a keyed handle. A handle is
 * matched against this user's other sessions, so it can never reach anyone else's.
 */
class ActiveSessions
{
    /**
     * Separates this key from every other use of APP_KEY.
     */
    private const KEY_LABEL = 'hr-system.session-handles.v1';

    /**
     * List the user's live sessions, the current one first, ready for display.
     *
     * @return Collection<int, array{handle: ?string, device: string, ip_address: ?string, last_active: CarbonImmutable, current: bool}>
     */
    public static function listFor(User $user, string $currentId): Collection
    {
        return static::rows($user)
            ->sortByDesc(fn (object $session) => [$session->id === $currentId, $session->last_activity])
            ->values()
            ->map(fn (object $session) => [
                'handle' => $session->id === $currentId ? null : static::handle($session->id),
                'device' => UserAgentLabel::for($session->user_agent),
                'ip_address' => $session->ip_address,
                'last_active' => CarbonImmutable::createFromTimestamp($session->last_activity, config('app.timezone')),
                'current' => $session->id === $currentId,
            ]);
    }

    /**
     * End one of the user's other sessions and report whether a session ended.
     */
    public static function endOne(User $user, string $currentId, string $handle): bool
    {
        $target = static::rows($user)
            ->reject(fn (object $session) => $session->id === $currentId)
            ->first(fn (object $session) => hash_equals(static::handle($session->id), $handle));

        if (! $target) {
            return false;
        }

        return DB::table('sessions')
            ->where('id', $target->id)
            ->where('user_id', $user->getKey())
            ->delete() === 1;
    }

    /**
     * Rows of the user that have not expired. Expired rows stay until garbage collection.
     *
     * @return Collection<int, object>
     */
    private static function rows(User $user): Collection
    {
        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->getTimestamp())
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);
    }

    /**
     * The handle of a session ID.
     */
    private static function handle(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, hash_hkdf('sha256', Crypt::getKey(), 32, self::KEY_LABEL));
    }
}
