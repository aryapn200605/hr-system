<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\RequestLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The soft lock of DECISIONS.md section 6: who has a request open. It only spares people from
 * working on the same request twice. DecideRequest makes the real check under row locks.
 *
 * A lock belongs to a user and not a page, so someone's second tab shares it. An expired row
 * is simply taken over by the next one to arrive, so nothing has to run in the background.
 */
class RequestLocks
{
    public const TTL_SECONDS = 300;

    /**
     * Take or extend the lock if it is free, expired or already this user's, and return who
     * holds it now. The one conditional update lets only one of two people take an expired lock.
     */
    public static function acquire(LeaveRequest|OvertimeRequest $request, User $user): ?RequestLock
    {
        $key = static::key($request);
        $expires = now()->addSeconds(self::TTL_SECONDS);

        DB::table('request_locks')->insertOrIgnore($key + ['user_id' => $user->getKey(), 'expires_at' => $expires]);

        DB::table('request_locks')
            ->where($key)
            ->where(fn ($query) => $query->where('user_id', $user->getKey())->orWhere('expires_at', '<=', now()))
            ->update(['user_id' => $user->getKey(), 'expires_at' => $expires]);

        return static::holder($request);
    }

    /**
     * Get the lock that is in force, if any.
     */
    public static function holder(LeaveRequest|OvertimeRequest $request): ?RequestLock
    {
        return RequestLock::query()
            ->with('user:id,name')
            ->where(static::key($request))
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Give up the lock, which does nothing unless this user holds it.
     */
    public static function release(string $kind, int $requestId, User $user): void
    {
        DB::table('request_locks')
            ->where(['request_kind' => $kind, 'request_id' => $requestId, 'user_id' => $user->getKey()])
            ->delete();
    }

    /**
     * Remove the lock of a request that no longer needs a decision.
     */
    public static function clear(LeaveRequest|OvertimeRequest $request): void
    {
        DB::table('request_locks')->where(static::key($request))->delete();
    }

    /**
     * @return array{request_kind: string, request_id: int}
     */
    private static function key(LeaveRequest|OvertimeRequest $request): array
    {
        return ['request_kind' => $request::KIND, 'request_id' => $request->getKey()];
    }
}
