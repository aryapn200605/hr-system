<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\PermissionName;
use App\Exceptions\AccountProtectionException;
use App\Models\AuditLog;
use App\Models\LeaveBalancePeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Yearly leave balances. Every change takes the user row lock first, so two changes to one
 * balance run one after the other. Periods of one user touch end to end, with no gaps and no
 * overlap, and a date always belongs to the period that covers it.
 *
 * A null actor means the system itself, such as the daily check or a console command.
 */
class LeaveBalances
{
    public const ANNUAL_ENTITLEMENT = 12;

    /**
     * The most a balance can hold, which also keeps it in its column.
     */
    public const MAX_DAYS = 366;

    private const DEADLOCK_RETRIES = 3;

    /**
     * Get the first reset date after a day. It always comes from the anchor and never from the last
     * period, so an anchor on 29 February moves to the 28th for a year and comes back in a leap year.
     */
    public static function nextResetAfter(CarbonInterface $anchor, CarbonInterface $day): CarbonImmutable
    {
        $thisYear = static::anniversary($anchor, $day->year);

        return $thisYear->greaterThan($day) ? $thisYear : static::anniversary($anchor, $day->year + 1);
    }

    /**
     * Open a user's first period, from today. The caller holds a transaction with the new user.
     */
    public static function openFirstPeriod(User $user, string $reason = 'First period of a new account.', ?User $actor = null): LeaveBalancePeriod
    {
        return static::open($user, CarbonImmutable::today(), $reason, $actor);
    }

    /**
     * Close every period that has ended and open the ones that follow, so a check that missed days
     * catches up. Safe to run any number of times, and it returns how many periods it opened.
     */
    public static function rollOver(User $user, string $source, ?string $operator = null): int
    {
        return DB::transaction(function () use ($user, $source, $operator) {
            return static::rollOverLocked(static::lockUser($user), $source, $operator);
        }, self::DEADLOCK_RETRIES);
    }

    /**
     * End the open period yesterday and open a new one from today, up to the next reset date.
     *
     * @throws AuthorizationException
     */
    public static function resetNow(User $user, ?User $actor, string $source, ?string $operator = null): void
    {
        static::authorize($actor);

        DB::transaction(function () use ($user, $actor, $source, $operator) {
            $user = static::lockUser($user);

            // An overdue balance gets its new period here, which is all a reset could give it today.
            if (static::rollOverLocked($user, $source, $operator) > 0) {
                return;
            }

            $period = static::openPeriod($user);
            $today = CarbonImmutable::today();

            if (! $period->start_date->lessThan($today)) {
                throw AccountProtectionException::alreadyResetToday();
            }

            $period->forceFill(['end_date' => $today->subDay()]);

            static::replace($user, $period, $today, $actor, $source, $operator);
        }, self::DEADLOCK_RETRIES);
    }

    /**
     * Change the open period by a number of days. Changes are relative, so two adjustments
     * at once both count, and the limits are checked on the values found under the lock.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function adjust(User $user, int $entitlementChange, int $usedChange, string $reason, User $actor): LeaveBalancePeriod
    {
        static::authorize($actor);

        return DB::transaction(function () use ($user, $entitlementChange, $usedChange, $reason, $actor) {
            $user = static::lockUser($user);
            $period = static::openPeriod($user) ?? throw AccountProtectionException::noOpenPeriod();

            $before = [$period->entitlement, $period->used];
            $entitlement = $period->entitlement + $entitlementChange;
            $used = $period->used + $usedChange;

            if ($entitlement < 0 || $entitlement > self::MAX_DAYS) {
                throw ValidationException::withMessages(['entitlementChange' => 'The entitlement must stay between 0 and '.self::MAX_DAYS.' days.']);
            }

            if ($used < 0 || $used > $entitlement) {
                throw ValidationException::withMessages(['usedChange' => 'Used days must stay between 0 and the entitlement.']);
            }

            $period->forceFill(['entitlement' => $entitlement, 'used' => $used])->save();

            static::ledger($period, LeaveEntryType::Adjusted, $actor, $reason, $before);

            AuditLog::record(AuditEvent::LeaveBalanceAdjusted, $actor, $user, [
                'period_id' => $period->getKey(),
                'entitlement' => ['before' => $before[0], 'after' => $entitlement],
                'used' => ['before' => $before[1], 'after' => $used],
                'reason' => $reason,
            ]);

            return $period;
        }, self::DEADLOCK_RETRIES);
    }

    /**
     * Change the reset date. The open period keeps its days and now ends before the next reset.
     *
     * @throws AuthorizationException
     */
    public static function changeAnchor(User $user, CarbonInterface $anchor, User $actor): void
    {
        static::authorize($actor);

        DB::transaction(function () use ($user, $anchor, $actor) {
            $user = static::lockUser($user);
            $oldAnchor = $user->leave_anchor_date;

            $user->forceFill(['leave_anchor_date' => $anchor])->save();

            $period = static::openPeriod($user);
            $oldEnd = $period?->end_date;

            if ($period) {
                $newEnd = static::nextResetAfter($anchor, CarbonImmutable::today())->subDay();

                $period->forceFill(['end_date' => $newEnd])->save();

                static::ledger(
                    $period,
                    LeaveEntryType::AnchorChanged,
                    $actor,
                    sprintf('Reset date changed from %s to %s.', $oldAnchor->toDateString(), $anchor->toDateString()),
                    [$period->entitlement, $period->used],
                );
            }

            AuditLog::record(AuditEvent::LeaveAnchorChanged, $actor, $user, [
                'anchor' => ['before' => $oldAnchor->toDateString(), 'after' => $anchor->toDateString()],
                'period_end' => ['before' => $oldEnd?->toDateString(), 'after' => $period?->end_date->toDateString()],
            ]);
        }, self::DEADLOCK_RETRIES);
    }

    private static function rollOverLocked(User $user, string $source, ?string $operator): int
    {
        $period = static::openPeriod($user);

        if (! $period) {
            static::openFirstPeriod($user, 'First period, opened by the daily check.');

            return 1;
        }

        $today = CarbonImmutable::today();
        $opened = 0;

        while ($period->end_date->lessThan($today)) {
            $period = static::replace($user, $period, $period->end_date->addDay(), null, $source, $operator);
            $opened++;
        }

        return $opened;
    }

    /**
     * Close a period and open the next one. What was left in it is gone, as there is no carry over.
     */
    private static function replace(User $user, LeaveBalancePeriod $period, CarbonImmutable $start, ?User $actor, string $source, ?string $operator): LeaveBalancePeriod
    {
        $forfeited = $period->remaining();

        $period->forceFill(['closed_at' => now()])->save();

        static::ledger($period, LeaveEntryType::Closed, $actor, "Period closed. {$forfeited} unused days expired.", [$period->entitlement, $period->used]);

        $next = static::open($user, $start, 'New period after the yearly reset.', $actor);

        AuditLog::record(AuditEvent::LeaveBalanceReset, $actor, $user, [
            'closed_period_id' => $period->getKey(),
            'opened_period_id' => $next->getKey(),
            'forfeited' => $forfeited,
            'source' => $source,
            'operator' => $operator,
        ]);

        return $next;
    }

    private static function open(User $user, CarbonImmutable $start, string $reason, ?User $actor): LeaveBalancePeriod
    {
        $period = $user->leavePeriods()->forceCreate([
            'start_date' => $start,
            'end_date' => static::nextResetAfter($user->leave_anchor_date, $start)->subDay(),
            'entitlement' => self::ANNUAL_ENTITLEMENT,
            'used' => 0,
        ]);

        static::ledger($period, LeaveEntryType::Opened, $actor, $reason);

        return $period;
    }

    /**
     * @param  array{0: int, 1: int}|null  $before  Entitlement and used days before the change.
     */
    private static function ledger(LeaveBalancePeriod $period, LeaveEntryType $type, ?User $actor, string $reason, ?array $before = null): void
    {
        $period->entries()->create([
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'entitlement_before' => $before[0] ?? null,
            'entitlement_after' => $period->entitlement,
            'used_before' => $before[1] ?? null,
            'used_after' => $period->used,
            'reason' => $reason,
        ]);
    }

    private static function lockUser(User $user): User
    {
        return User::query()->lockForUpdate()->findOrFail($user->getKey());
    }

    private static function openPeriod(User $user): ?LeaveBalancePeriod
    {
        return $user->leavePeriods()->whereNull('closed_at')->orderByDesc('start_date')->lockForUpdate()->first();
    }

    private static function anniversary(CarbonInterface $anchor, int $year): CarbonImmutable
    {
        $firstOfMonth = CarbonImmutable::create($year, $anchor->month, 1);

        return $firstOfMonth->day(min($anchor->day, $firstOfMonth->daysInMonth));
    }

    private static function authorize(?User $actor): void
    {
        if ($actor && ! $actor->hasPermissionTo(PermissionName::LeaveBalanceAdjust)) {
            throw new AuthorizationException;
        }
    }
}
