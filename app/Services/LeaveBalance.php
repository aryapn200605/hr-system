<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Leave periods and their ledger (DECISIONS.md sections 5 and 9).
 *
 * A period runs from one anniversary of the user's anchor date to the day before the next one,
 * with 12 days and no carry over. Anniversaries are counted from the anchor, never from the previous
 * period, so an anchor on 29 February falls on 28 February in other years and back on the 29th in
 * leap years. Every change locks the user row first, which serializes the daily job, manual resets
 * and adjustments of one user, so the period rows need no lock of their own.
 */
class LeaveBalance
{
    public const ENTITLEMENT = 12;

    public const MAX_ADJUSTMENT = 365;

    public const EARLIEST_ANCHOR = '2000-01-01';

    public function __construct(private UserPolicy $policy) {}

    /**
     * Open every period that should have started by today, and return the current one. Idempotent:
     * a second run on the same day changes nothing, and a run after missed days fills each missed
     * period in turn, so no year is skipped.
     */
    public function sync(User $user): LeavePeriod
    {
        return DB::transaction(function () use ($user) {
            $anchor = $this->lockUser($user);
            $today = CarbonImmutable::today();

            $latest = LeavePeriod::query()->where('user_id', $user->getKey())->orderByDesc('starts_on')->first();

            if (! $latest) {
                // The first period is the one that contains today, with the full entitlement (DECISIONS.md section 5).
                $start = $this->anniversary($anchor, $this->yearsBefore($anchor, $today));

                return $this->open($user, $start, $this->endFor($anchor, $start), null, null);
            }

            while ($latest->ends_on->lt($today)) {
                $start = CarbonImmutable::instance($latest->ends_on)->addDay();
                $latest = $this->open($user, $start, $this->endFor($anchor, $start), null, null);

                AuditLog::record(AuditEvent::LeaveBalanceReset, subject: $user, metadata: [
                    'source' => 'schedule',
                    'opened_period_id' => $latest->getKey(),
                ]);
            }

            return $latest;
        });
    }

    /**
     * Run sync() for every user, one transaction each, and return how many were checked.
     * PENDING and SUSPENDED users are included, so their periods never break.
     */
    public function syncAll(): int
    {
        $count = 0;

        User::query()->select('id')->orderBy('id')->chunkById(200, function ($users) use (&$count) {
            foreach ($users as $user) {
                $this->sync($user);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Add or remove days in the current period, with a reason. The remaining days may not go below zero.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function adjust(User $actor, User $target, mixed $days, mixed $reason): LeavePeriod
    {
        $this->authorize($actor, $target);

        $validated = Validator::make(['days' => $days, 'reason' => is_string($reason) ? trim($reason) : $reason], [
            'days' => ['required', 'integer', 'not_in:0', 'between:-'.self::MAX_ADJUSTMENT.','.self::MAX_ADJUSTMENT],
            'reason' => $this->reasonRules(),
        ], [
            'days.not_in' => __('Enter a number of days other than zero.'),
        ], $this->attributeNames())->validate();

        $change = (int) $validated['days'];

        return DB::transaction(function () use ($actor, $target, $change, $validated) {
            $period = $this->sync($target);
            $before = $period->remaining();
            $after = $before + $change;

            if ($after < 0) {
                throw ValidationException::withMessages(['days' => __('Only :count days remain, so no more than :count can be removed.', ['count' => $before])]);
            }

            $period->entitlement += $change;
            $period->save();

            $this->entry($period, LeaveEntryType::Adjusted, $change, $before, $actor, $validated['reason']);

            AuditLog::record(AuditEvent::LeaveBalanceAdjusted, $actor, $target, [
                'period_id' => $period->getKey(),
                'change' => $change,
                'before' => $before,
                'after' => $after,
            ]);

            return $period;
        });
    }

    /**
     * End the current period yesterday and open a new one today until the day before the next
     * anniversary of the anchor, which does not move. Refused when the current period starts today.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function reset(User $actor, User $target, mixed $reason): LeavePeriod
    {
        $this->authorize($actor, $target);

        $validated = Validator::make(['reason' => is_string($reason) ? trim($reason) : $reason], [
            'reason' => $this->reasonRules(),
        ], [], $this->attributeNames())->validate();

        return DB::transaction(function () use ($actor, $target, $validated) {
            $current = $this->sync($target);
            $today = CarbonImmutable::today();

            if ($current->starts_on->gte($today)) {
                throw ValidationException::withMessages(['reason' => __('The current period started today, so there is nothing to reset.')]);
            }

            $remaining = $current->remaining();
            $current->ends_on = $today->subDay();
            $current->save();

            $this->entry($current, LeaveEntryType::Closed, 0, $remaining, $actor, $validated['reason']);

            $anchor = CarbonImmutable::instance($target->newQuery()->findOrFail($target->getKey())->leave_anchor_date);
            $opened = $this->open($target, $today, $this->endFor($anchor, $today), $actor, $validated['reason']);

            AuditLog::record(AuditEvent::LeaveBalanceReset, $actor, $target, [
                'source' => 'manual',
                'closed_period_id' => $current->getKey(),
                'opened_period_id' => $opened->getKey(),
            ]);

            return $opened;
        });
    }

    /**
     * Change the anchor date. The current period keeps its dates; the new anchor sets the end of
     * every period from the next one on, so that next period can be shorter than a year.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function changeAnchor(User $actor, User $target, mixed $date): void
    {
        $this->authorize($actor, $target);

        $validated = Validator::make(['anchor' => $date], [
            'anchor' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST_ANCHOR, 'before_or_equal:today'],
        ], [], $this->attributeNames())->validate();

        DB::transaction(function () use ($actor, $target, $validated) {
            $before = $this->lockUser($target)->toDateString();

            if ($before === $validated['anchor']) {
                return;
            }

            $target->newQuery()->whereKey($target->getKey())->update(['leave_anchor_date' => $validated['anchor']]);
            $target->leave_anchor_date = $validated['anchor'];
            $target->syncOriginalAttribute('leave_anchor_date');

            AuditLog::record(AuditEvent::LeaveAnchorChanged, $actor, $target, ['from' => $before, 'to' => $validated['anchor']]);
        });
    }

    /**
     * The anchor's anniversary after this many years. addYearsNoOverflow turns 29 February into 28 February.
     */
    public function anniversary(CarbonInterface $anchor, int $years): CarbonImmutable
    {
        return CarbonImmutable::instance($anchor)->startOfDay()->addYearsNoOverflow($years);
    }

    /**
     * The last day of a period that starts on $start: the day before the first anniversary after $start.
     */
    public function endFor(CarbonInterface $anchor, CarbonInterface $start): CarbonImmutable
    {
        // A start before the anchor happens when HR moves the anchor later than the current period's start.
        $years = $start->lt($anchor) ? 0 : $this->yearsBefore($anchor, $start);

        while ($this->anniversary($anchor, $years)->lte($start)) {
            $years++;
        }

        return $this->anniversary($anchor, $years)->subDay();
    }

    /**
     * The number of whole years from the anchor to $date, by anniversary.
     */
    private function yearsBefore(CarbonInterface $anchor, CarbonInterface $date): int
    {
        if ($date->lt($anchor)) {
            throw new LogicException('A leave anchor date cannot be after the day it is used for.');
        }

        $years = $date->year - $anchor->year;

        return $this->anniversary($anchor, $years)->gt($date) ? $years - 1 : $years;
    }

    /**
     * Lock the user row and return the anchor date as stored now.
     */
    private function lockUser(User $user): CarbonImmutable
    {
        $locked = User::query()->select(['id', 'leave_anchor_date'])->lockForUpdate()->findOrFail($user->getKey());

        return CarbonImmutable::instance($locked->leave_anchor_date);
    }

    private function open(User $user, CarbonInterface $start, CarbonInterface $end, ?User $actor, ?string $reason): LeavePeriod
    {
        $period = LeavePeriod::query()->create([
            'user_id' => $user->getKey(),
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'entitlement' => self::ENTITLEMENT,
            'used' => 0,
        ]);

        $this->entry($period, LeaveEntryType::Opened, self::ENTITLEMENT, 0, $actor, $reason);

        return $period;
    }

    private function entry(LeavePeriod $period, LeaveEntryType $type, int $change, int $before, ?User $actor, ?string $reason): void
    {
        LeaveBalanceEntry::query()->create([
            'leave_period_id' => $period->getKey(),
            'user_id' => $period->user_id,
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'change' => $change,
            'before' => $before,
            'after' => $before + $change,
            'reason' => $reason,
        ]);
    }

    /**
     * Field names in validation messages, in the user's language.
     *
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return ['days' => __('days'), 'reason' => __('reason'), 'anchor' => __('anchor date')];
    }

    /**
     * @return list<string>
     */
    private function reasonRules(): array
    {
        return ['required', 'string', 'min:10', 'max:1000'];
    }

    private function authorize(User $actor, User $target): void
    {
        if (! $this->policy->adjustLeaveBalance($actor, $target)) {
            throw new AuthorizationException;
        }
    }
}
