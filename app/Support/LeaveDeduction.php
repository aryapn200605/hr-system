<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Exceptions\InsufficientLeaveBalance;
use App\Models\LeaveBalancePeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAllocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes approved days from the leave periods their dates fall in.
 *
 * Days in a period that does not exist yet stay unmapped and count against the projected period.
 * LeaveBalances moves them onto the real period in the transaction that opens it. Closed periods
 * can only change here, for days before a reset approved after it.
 */
class LeaveDeduction
{
    /**
     * Split a request's days by period. Reading it needs no lock. Deducting does, in period order.
     *
     * @return list<array{window: LeavePeriodWindow, period: ?LeaveBalancePeriod, allocations: list<LeaveRequestAllocation>}>
     */
    public static function plan(LeaveRequest $request, User $requester, bool $lock = false): array
    {
        $periods = $requester->leavePeriods()->orderBy('start_date')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $parts = [];

        foreach ($request->allocations()->orderBy('work_date')->get() as $allocation) {
            $period = $periods->first(fn (LeaveBalancePeriod $period) => static::covers($period, $allocation->work_date));
            $window = $period ? static::real($period) : static::projected($requester, $periods->last(), $allocation->work_date);
            $key = $window->start->toDateString();

            $parts[$key] ??= ['window' => $window, 'period' => $period, 'allocations' => []];
            $parts[$key]['allocations'][] = $allocation;
        }

        return array_values($parts);
    }

    /**
     * Check every part first, so a request that does not fit changes nothing, then deduct.
     * The caller holds the requester's row lock and has rolled over overdue periods.
     *
     * @throws InsufficientLeaveBalance
     */
    public static function apply(LeaveRequest $request, User $requester, User $actor): void
    {
        $parts = static::plan($request, $requester, true);

        foreach ($parts as ['window' => $window, 'allocations' => $allocations]) {
            $left = $window->entitlement - $window->used;

            if (count($allocations) > $left) {
                throw new InsufficientLeaveBalance($window->label(), count($allocations), $left);
            }
        }

        foreach ($parts as ['period' => $period, 'allocations' => $allocations]) {
            if ($period) {
                LeaveBalances::applyApprovedDays($period, array_column($allocations, 'id'), $request->getKey(), $actor);
            }
        }
    }

    private static function covers(LeaveBalancePeriod $period, CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($period->start_date, $period->end_date);
    }

    private static function real(LeaveBalancePeriod $period): LeavePeriodWindow
    {
        return new LeavePeriodWindow($period->start_date, $period->end_date, $period->entitlement, $period->used, false);
    }

    /**
     * Project the period a later date will fall in. Its `used` is what was approved there so far.
     */
    private static function projected(User $requester, ?LeaveBalancePeriod $last, CarbonImmutable $date): LeavePeriodWindow
    {
        $start = $last ? $last->end_date->addDay() : CarbonImmutable::today();
        $end = LeaveBalances::nextResetAfter($requester->leave_anchor_date, $start)->subDay();

        while ($date->greaterThan($end)) {
            $start = $end->addDay();
            $end = LeaveBalances::nextResetAfter($requester->leave_anchor_date, $start)->subDay();
        }

        $approved = DB::table('leave_request_allocations')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_allocations.leave_request_id')
            ->where('leave_requests.user_id', $requester->getKey())
            ->where('leave_requests.status', RequestStatus::Approved->value)
            ->whereNull('leave_request_allocations.period_id')
            ->whereBetween('leave_request_allocations.work_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        return new LeavePeriodWindow($start, $end, LeaveBalances::ANNUAL_ENTITLEMENT, $approved, true);
    }
}
