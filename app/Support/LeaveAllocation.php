<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How requests reach into leave periods. Callers hold the user row lock.
 */
class LeaveAllocation
{
    /**
     * Get the open period and the one after it, which is as far ahead as a request can go.
     * The next one comes from the reset date, as the daily check has not created it yet.
     *
     * @return array{0: LeavePeriodWindow, 1: LeavePeriodWindow}
     */
    public static function windows(User $user): array
    {
        LeaveBalances::rollOverLocked($user, 'request', null);

        $open = $user->leavePeriods()->whereNull('closed_at')->sole();
        $nextStart = $open->end_date->addDay();

        return [
            new LeavePeriodWindow($open->start_date, $open->end_date, $open->entitlement, $open->used, false),
            new LeavePeriodWindow(
                $nextStart,
                LeaveBalances::nextResetAfter($user->leave_anchor_date, $nextStart)->subDay(),
                LeaveBalances::ANNUAL_ENTITLEMENT,
                0,
                true,
            ),
        ];
    }

    /**
     * Get the days a window still has for a new request.
     *
     * A real period counts approved days in `used`, so only pending days come off here. A projected
     * period has no `used` yet, so its approved days have to come off here too.
     */
    public static function available(User $user, LeavePeriodWindow $window): int
    {
        $statuses = $window->projected ? RequestStatus::holding() : [RequestStatus::Pending];

        $reserved = DB::table('leave_request_allocations')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_allocations.leave_request_id')
            ->where('leave_requests.user_id', $user->getKey())
            ->whereIn('leave_requests.status', array_map(fn (RequestStatus $status) => $status->value, $statuses))
            ->whereBetween('leave_request_allocations.work_date', [$window->start->toDateString(), $window->end->toDateString()])
            ->count();

        return $window->entitlement - $window->used - $reserved;
    }
}
