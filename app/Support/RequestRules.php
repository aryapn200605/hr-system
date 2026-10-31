<?php

namespace App\Support;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The rules of DECISIONS.md section 8 that depend on stored rows. Callers hold the user row lock,
 * so two requests of one user are checked one after the other. Every message is about the user's
 * own requests, so naming the dates shows nothing about anyone else.
 */
class RequestRules
{
    public const MAX_OVERTIME_HOURS = 12;

    /**
     * Refuse someone with no one to decide their requests.
     */
    public static function ensureEligible(User $user): void
    {
        $reason = RequestEligibility::blockedReason($user);

        if ($reason !== null) {
            throw ValidationException::withMessages(['form' => $reason]);
        }
    }

    /**
     * Refuse leave dates that share a calendar day with another leave request.
     */
    public static function ensureNoLeaveOverlap(User $user, CarbonImmutable $start, CarbonImmutable $end, string $field): void
    {
        $other = LeaveRequest::query()
            ->whereBelongsTo($user)
            ->holding()
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->first();

        if ($other) {
            throw ValidationException::withMessages([$field => sprintf(
                'This overlaps your %s leave request of %s.',
                $other->status->value,
                static::dateRange($other->start_date, $other->end_date),
            )]);
        }
    }

    /**
     * Refuse leave dates that an overtime request touches. Comparing against whole
     * days makes an overtime that ends at 00:00 leave the next date untouched.
     */
    public static function ensureNoOvertimeOnDates(User $user, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $other = OvertimeRequest::query()
            ->whereBelongsTo($user)
            ->holding()
            ->where('start_at', '<', $end->addDay()->startOfDay())
            ->where('end_at', '>', $start->startOfDay())
            ->orderBy('start_at')
            ->first();

        if ($other) {
            throw ValidationException::withMessages(['start_date' => sprintf(
                'This touches your %s overtime request of %s.',
                $other->status->value,
                static::timeRange($other->start_at, $other->end_at),
            )]);
        }
    }

    /**
     * Refuse overtime that overlaps other overtime. One ending as this one starts is fine.
     */
    public static function ensureNoOvertimeOverlap(User $user, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $other = OvertimeRequest::query()
            ->whereBelongsTo($user)
            ->holding()
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->orderBy('start_at')
            ->first();

        if ($other) {
            throw ValidationException::withMessages(['start_at' => sprintf(
                'This overlaps your %s overtime request of %s.',
                $other->status->value,
                static::timeRange($other->start_at, $other->end_at),
            )]);
        }
    }

    /**
     * Refuse overtime on any date of a leave request. Overtime ending at 00:00 has no hour on that date.
     */
    public static function ensureNoLeaveOnOvertimeDates(User $user, CarbonImmutable $start, CarbonImmutable $end): void
    {
        static::ensureNoLeaveOverlap($user, $start->startOfDay(), $end->subSecond()->startOfDay(), 'start_at');
    }

    /**
     * Refuse leave beyond the end of the next period.
     *
     * @param  array{0: LeavePeriodWindow, 1: LeavePeriodWindow}  $windows
     */
    public static function ensureWithinHorizon(array $windows, CarbonImmutable $end): void
    {
        if ($end->greaterThan($windows[1]->end)) {
            throw ValidationException::withMessages([
                'end_date' => 'Leave can be requested up to '.$windows[1]->end->format('j M Y').', the end of your next leave period.',
            ]);
        }
    }

    /**
     * Refuse when any part of the request is more than its period has left.
     *
     * @param  array{0: LeavePeriodWindow, 1: LeavePeriodWindow}  $windows
     * @param  list<string>  $days
     */
    public static function ensureBalanceCovers(User $user, array $windows, array $days): void
    {
        foreach ($windows as $window) {
            $needed = count(array_filter($days, fn (string $day) => $window->covers($day)));

            if ($needed === 0) {
                continue;
            }

            $available = LeaveAllocation::available($user, $window);

            if ($needed > $available) {
                throw ValidationException::withMessages(['start_date' => sprintf(
                    'This needs %d working days from your leave period %s, which has %d left after your pending requests.',
                    $needed,
                    $window->label(),
                    max($available, 0),
                )]);
            }
        }
    }

    private static function dateRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->isSameDay($end)
            ? $start->format('j M Y')
            : $start->format('j M Y').' to '.$end->format('j M Y');
    }

    private static function timeRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('j M Y, H:i').' to '.$end->format('j M Y, H:i');
    }
}
