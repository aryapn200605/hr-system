<?php

namespace App\Services\Requests;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The soft lock of DECISIONS.md section 6, first layer. A decider who opens a pending request holds it
 * for a few minutes; the page renews it while open and releases it when closed. Anyone else who may
 * decide sees the request in view-only mode, with the holder's name. Expiry is checked whenever the
 * lock is read or taken; no background job clears it.
 *
 * The lock lives in two columns of the request row and every change is one conditional UPDATE, so two
 * deciders taking it at once cannot both win. It never replaces the check at submit time
 * (App\Services\Requests\Decisions), which locks the row and reads it again.
 */
class ReviewLock
{
    /**
     * Take or renew the lock for this user, unless someone else holds a lock that has not expired.
     * Returns the holder afterwards: this user when it worked, the other decider otherwise, or null
     * when the request is no longer pending.
     *
     * @param  class-string<LeaveRequest|OvertimeRequest>  $model
     */
    public function acquire(User $user, string $model, int $id): ?ReviewLockHolder
    {
        $now = CarbonImmutable::now();

        $model::query()
            ->whereKey($id)
            ->where('status', RequestStatus::Pending->value)
            ->where(fn ($query) => $query->whereNull('review_locked_by')
                ->orWhere('review_locked_by', $user->getKey())
                ->orWhere('review_locked_until', '<=', $now))
            ->toBase()
            ->update([
                'review_locked_by' => $user->getKey(),
                'review_locked_until' => $now->addMinutes((int) config('requests.review_lock_minutes')),
            ]);

        // Read back instead of trusting the row count: MySQL reports 0 changed rows when a renewal in the
        // same second writes the same values.
        return $this->holder($model, $id);
    }

    /**
     * Who holds a live lock on this request now, or null when nobody does or it is no longer pending.
     *
     * @param  class-string<LeaveRequest|OvertimeRequest>  $model
     */
    public function holder(string $model, int $id): ?ReviewLockHolder
    {
        $table = (new $model)->getTable();

        $row = $model::query()->toBase()
            ->leftJoin('users', 'users.id', '=', $table.'.review_locked_by')
            ->where($table.'.id', $id)
            ->first([$table.'.status', $table.'.review_locked_by', $table.'.review_locked_until', 'users.name']);

        return $row === null ? null : ReviewLockHolder::fromRow($row);
    }

    /**
     * Let go of the lock, if this user holds it.
     *
     * @param  class-string<LeaveRequest|OvertimeRequest>  $model
     */
    public function release(User $user, string $model, int $id): void
    {
        $model::query()
            ->whereKey($id)
            ->where('review_locked_by', $user->getKey())
            ->toBase()
            ->update(['review_locked_by' => null, 'review_locked_until' => null]);
    }
}
