<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\RequestStatus;
use App\Exceptions\InsufficientLeaveBalanceException;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAllocation;
use App\Models\User;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
 * leap years. Every change locks the user row first, which serializes the daily job, manual resets,
 * adjustments and approvals of one user. An approval also locks the periods it charges, by start date
 * (README "Approvals", lock order).
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

            $this->entry($period, LeaveEntryType::Adjusted, $change, $before, $actor->getKey(), $validated['reason']);

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

            $this->entry($current, LeaveEntryType::Closed, 0, $remaining, $actor->getKey(), $validated['reason']);

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

        $this->entry($period, LeaveEntryType::Opened, self::ENTITLEMENT, 0, $actor?->getKey(), $reason);

        $this->chargeDeferred($period);

        return $period;
    }

    /**
     * Deduct the approved parts that were waiting for this period to open (see chargeApproved()). Each
     * ledger row names the request and, as actor, the person who approved it.
     */
    private function chargeDeferred(LeavePeriod $period): void
    {
        $allocations = $this->deferredAllocations($period->user_id, CarbonImmutable::instance($period->starts_on), CarbonImmutable::instance($period->ends_on))
            ->orderBy('leave_request_allocations.starts_on')
            ->orderBy('leave_request_allocations.id')
            ->get(['leave_request_allocations.*']);

        if ($allocations->isEmpty()) {
            return;
        }

        $deciders = LeaveRequest::query()->whereKey($allocations->pluck('leave_request_id'))->pluck('decided_by', 'id');

        foreach ($allocations as $allocation) {
            $this->charge($period, $allocation, $deciders[$allocation->leave_request_id] ?? null);
        }
    }

    /**
     * Deduct the days of an approved leave request (DECISIONS.md sections 5 and 6), inside the caller's
     * transaction, which already holds the request row.
     *
     * Lock order: the requester's user row (through sync()), then the periods to charge, by start date.
     * Each part of the request is charged to the real period that holds the part's first day. Normally
     * that is the period with the same start date as at submission; after a manual reset or an anchor
     * change it is the period that now holds those dates, the same rule that counts pending days. A
     * period that has started but was not opened yet is opened by sync() here. A closed period can be
     * charged this way, and only this way. A part in a period that has not started yet is checked
     * against that projected period (12 days minus other approved parts there) and charged by sync()
     * when the period opens.
     *
     * @return list<array{period_starts_on: string, days: int, deferred: bool}>
     *
     * @throws InsufficientLeaveBalanceException
     */
    public function chargeApproved(User $requester, LeaveRequest $request, User $actor): array
    {
        $latest = $this->sync($requester);
        $anchor = CarbonImmutable::instance(User::query()->findOrFail($requester->getKey())->leave_anchor_date);

        // A pending request has no charged part yet: charging happens only here and in chargeDeferred().
        $allocations = $request->allocations()->reorder('starts_on')->get();

        /** @var array<int, list<LeaveRequestAllocation>> $charges */
        $charges = [];
        /** @var array<string, array{start: CarbonImmutable, end: CarbonImmutable, days: int}> $deferred */
        $deferred = [];

        foreach ($allocations as $allocation) {
            $date = CarbonImmutable::instance($allocation->starts_on);

            if ($date->gt($latest->ends_on)) {
                [$start, $end] = $this->futureRangeHolding($anchor, $latest, $date);
                $deferred[$start->toDateString()] ??= ['start' => $start, 'end' => $end, 'days' => 0];
                $deferred[$start->toDateString()]['days'] += $allocation->days;

                continue;
            }

            $periodId = LeavePeriod::query()
                ->where('user_id', $requester->getKey())
                ->where('starts_on', '<=', $date->toDateString())
                ->where('ends_on', '>=', $date->toDateString())
                ->value('id') ?? throw new LogicException('No leave period holds '.$date->toDateString().'.');

            $charges[$periodId][] = $allocation;
        }

        $periods = LeavePeriod::query()->whereKey(array_keys($charges))->orderBy('starts_on')->lockForUpdate()->get();

        foreach ($periods as $period) {
            $days = array_sum(array_map(fn (LeaveRequestAllocation $allocation) => $allocation->days, $charges[$period->getKey()]));

            if ($days > $period->remaining()) {
                throw new InsufficientLeaveBalanceException(
                    CarbonImmutable::instance($period->starts_on),
                    CarbonImmutable::instance($period->ends_on),
                    $days,
                    max(0, $period->remaining()),
                );
            }
        }

        foreach ($deferred as $range) {
            $available = self::ENTITLEMENT - $this->deferredAllocations($requester->getKey(), $range['start'], $range['end'])->sum('leave_request_allocations.days');

            if ($range['days'] > $available) {
                throw new InsufficientLeaveBalanceException($range['start'], $range['end'], $range['days'], max(0, $available));
            }
        }

        $summary = [];

        foreach ($periods as $period) {
            foreach ($charges[$period->getKey()] as $allocation) {
                $this->charge($period, $allocation, $actor->getKey());
                $summary[] = ['period_starts_on' => $period->starts_on->toDateString(), 'days' => $allocation->days, 'deferred' => false];
            }
        }

        foreach ($deferred as $key => $range) {
            $summary[] = ['period_starts_on' => $key, 'days' => $range['days'], 'deferred' => true];
        }

        return $summary;
    }

    /**
     * Days of approved leave waiting for a period that has not started yet, by the first day of each part.
     * The projected next period counts them as used.
     */
    public function deferredDays(User $user, CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) $this->deferredAllocations($user->getKey(), $start, $end)->sum('leave_request_allocations.days');
    }

    /**
     * Approved parts not charged yet whose first day falls between these dates.
     *
     * @return Builder<LeaveRequestAllocation>
     */
    private function deferredAllocations(int $userId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return LeaveRequestAllocation::query()
            ->join('leave_requests as lr', 'lr.id', '=', 'leave_request_allocations.leave_request_id')
            ->where('lr.user_id', $userId)
            ->where('lr.status', RequestStatus::Approved->value)
            ->whereNull('leave_request_allocations.charged_at')
            ->whereBetween('leave_request_allocations.starts_on', [$start->toDateString(), $end->toDateString()]);
    }

    /**
     * The dates of the not yet opened period that will hold $date, counted from the anchor after the latest real period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function futureRangeHolding(CarbonImmutable $anchor, LeavePeriod $latest, CarbonImmutable $date): array
    {
        $start = CarbonImmutable::instance($latest->ends_on)->addDay();
        $end = $this->endFor($anchor, $start);

        while ($end->lt($date)) {
            $start = $end->addDay();
            $end = $this->endFor($anchor, $start);
        }

        return [$start, $end];
    }

    private function charge(LeavePeriod $period, LeaveRequestAllocation $allocation, ?int $actorId): void
    {
        $before = $period->remaining();
        $period->used += $allocation->days;
        $period->save();

        $this->entry($period, LeaveEntryType::Deducted, -$allocation->days, $before, $actorId, null, $allocation->leave_request_id);

        $allocation->leave_period_id = $period->getKey();
        $allocation->charged_at = now();
        $allocation->save();
    }

    private function entry(LeavePeriod $period, LeaveEntryType $type, int $change, int $before, ?int $actorId, ?string $reason, ?int $requestId = null): void
    {
        LeaveBalanceEntry::query()->create([
            'leave_period_id' => $period->getKey(),
            'user_id' => $period->user_id,
            'actor_id' => $actorId,
            'leave_request_id' => $requestId,
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
