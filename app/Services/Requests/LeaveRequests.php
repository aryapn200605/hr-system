<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveCategory;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAllocation;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\LeaveBalance;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Leave and permission requests (DECISIONS.md sections 4, 5 and 8).
 *
 * A request of a deducting category is split over leave periods: the current one and, when the dates
 * reach past the next reset, the next one, projected from the anchor date with 12 days if the daily job
 * has not opened it yet. A request may not end after the end of that next period. Each part must fit
 * in what its period has left after the allocations of the user's pending requests. `used` changes
 * only on approval (App\Services\Requests\Decisions).
 */
class LeaveRequests
{
    public function __construct(
        private Submission $submission,
        private LeaveBalance $balance,
        private WorkingDays $workingDays,
        private Evidence $evidence,
        private RequestNotifications $notifications,
    ) {}

    /**
     * @param  array{category?: mixed, starts_on?: mixed, ends_on?: mixed, reason?: mixed}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function submit(User $actor, array $input, mixed $evidence = null): LeaveRequest
    {
        $this->submission->authorizeAndThrottle($actor);

        $reason = $input['reason'] ?? null;

        $validated = Validator::make([
            'category' => $input['category'] ?? null,
            'starts_on' => $input['starts_on'] ?? null,
            'ends_on' => $input['ends_on'] ?? null,
            'reason' => is_string($reason) ? trim($reason) : $reason,
        ], [
            'category' => ['required', 'string', 'max:30'],
            'starts_on' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => $this->submission->reasonRules(),
        ], [
            'starts_on.after_or_equal' => __('The start date cannot be before today.'),
            'ends_on.after_or_equal' => __('The end date cannot be before the start date.'),
        ], $this->attributeNames())->validate();

        $this->evidence->validate($evidence);
        $stored = $this->evidence->store($evidence instanceof UploadedFile ? $evidence : null);

        try {
            $request = DB::transaction(fn () => $this->create($actor, $validated, $stored));
        } catch (Throwable $exception) {
            $this->evidence->discard($stored);

            throw $exception;
        }

        // After the commit, and never able to fail the request (Phase 13).
        $this->notifications->submitted($request, $actor);

        return $request;
    }

    /**
     * Cancel one of the actor's own pending requests.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function cancel(User $actor, mixed $id): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $id) {
            $request = $this->submission->cancel($actor, LeaveRequest::class, $id);

            AuditLog::record(AuditEvent::LeaveRequestCancelled, $actor, $request, [
                'starts_on' => $request->starts_on->toDateString(),
                'ends_on' => $request->ends_on->toDateString(),
            ]);

            return $request;
        });
    }

    /**
     * The current and next period of a user with what is still available in each, for the screen.
     *
     * @return list<LeavePeriodSlot>
     */
    public function slots(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $current = $this->balance->sync($user);

            return $this->slotsAfter($user, $current, $user->leave_anchor_date);
        });
    }

    /**
     * @param  array<string, string>  $validated
     * @param  array<string, mixed>  $stored
     */
    private function create(User $actor, array $validated, array $stored): LeaveRequest
    {
        $user = $this->submission->lockRequester($actor);

        $category = LeaveCategory::query()->where('code', $validated['category'])->first();

        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages(['category' => __('Choose an active leave category.')]);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $validated['starts_on']);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $validated['ends_on']);

        $current = $this->balance->sync($user);
        $slots = $this->slotsAfter($user, $current, $user->leave_anchor_date);
        $horizon = end($slots)->endsOn;

        if ($end->gt($horizon)) {
            throw ValidationException::withMessages(['ends_on' => __('The end date can be :date at the latest, the last day of your next leave period.', [
                'date' => $horizon->translatedFormat('j M Y'),
            ])]);
        }

        $days = $this->workingDays->count($start, $end);

        if ($days < 1) {
            throw ValidationException::withMessages(['ends_on' => __('These dates contain no working day.')]);
        }

        $this->ensureNoOverlap($user, $start, $end);

        $parts = $category->deducts_balance ? $this->allocate($slots, $start, $end) : [];

        $request = new LeaveRequest;
        $request->user_id = $user->getKey();
        $request->leave_category_id = $category->getKey();
        $request->starts_on = $start;
        $request->ends_on = $end;
        $request->working_days = $days;
        $request->deducts_balance = $category->deducts_balance;
        $request->reason = $validated['reason'];
        $request->status = RequestStatus::Pending;
        $request->forceFill($stored)->save();

        foreach ($parts as $part) {
            $allocation = new LeaveRequestAllocation;
            $allocation->forceFill(['leave_request_id' => $request->getKey()] + $part)->save();
        }

        AuditLog::record(AuditEvent::LeaveRequestCreated, $actor, $request, [
            'category' => $category->code,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'working_days' => $days,
            'allocations' => array_map(fn (array $part) => [
                'period_starts_on' => $part['period_starts_on'],
                'days' => $part['days'],
                'projected' => $part['leave_period_id'] === null,
            ], $parts),
        ]);

        if ($stored !== []) {
            AuditLog::record(AuditEvent::RequestEvidenceUploaded, $actor, $request, [
                'mime' => $stored['evidence_mime'],
                'size' => $stored['evidence_size'],
            ]);
        }

        return $request;
    }

    /**
     * Refuse dates that overlap the user's pending or approved leave, or a date touched by their
     * pending or approved overtime. Rejected and cancelled requests do not count.
     */
    private function ensureNoOverlap(User $user, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $leave = LeaveRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', RequestStatus::blocking())
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->exists();

        if ($leave) {
            throw ValidationException::withMessages(['starts_on' => __('These dates overlap another leave request of yours that is pending or approved.')]);
        }

        $overtime = OvertimeRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', RequestStatus::blocking())
            ->where('first_date', '<=', $end->toDateString())
            ->where('last_date', '>=', $start->toDateString())
            ->exists();

        if ($overtime) {
            throw ValidationException::withMessages(['starts_on' => __('These dates overlap an overtime request of yours that is pending or approved.')]);
        }
    }

    /**
     * Split the working days over the periods and check each part against what is available there.
     *
     * @param  list<LeavePeriodSlot>  $slots
     * @return list<array<string, mixed>>
     */
    private function allocate(array $slots, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $parts = [];

        foreach ($slots as $slot) {
            $from = $start->max($slot->startsOn);
            $to = $end->min($slot->endsOn);
            $days = $this->workingDays->count($from, $to);

            if ($days === 0) {
                continue;
            }

            if ($days > $slot->available()) {
                throw ValidationException::withMessages(['request' => __('Not enough leave balance in the period :start – :end: :days working days requested, :available available.', [
                    'start' => $slot->startsOn->translatedFormat('j M Y'),
                    'end' => $slot->endsOn->translatedFormat('j M Y'),
                    'days' => $days,
                    'available' => $slot->available(),
                ])]);
            }

            $parts[] = [
                'leave_period_id' => $slot->id,
                'period_starts_on' => $slot->startsOn->toDateString(),
                'period_ends_on' => $slot->endsOn->toDateString(),
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'days' => $days,
            ];
        }

        return $parts;
    }

    /**
     * The current period and the next one. The next one is always projected from the anchor date:
     * LeaveBalance::sync() opens a period only once it has started, so it never exists in advance.
     *
     * @return list<LeavePeriodSlot>
     */
    private function slotsAfter(User $user, LeavePeriod $current, mixed $anchor): array
    {
        $start = CarbonImmutable::instance($current->starts_on);
        $end = CarbonImmutable::instance($current->ends_on);
        $nextStart = $end->addDay();
        $nextEnd = $this->balance->endFor(CarbonImmutable::instance($anchor), $nextStart);

        return [
            new LeavePeriodSlot($current->getKey(), $start, $end, $current->entitlement, $current->used, $this->pending($user, $start, $end)),
            // Approved leave in the next period is charged when the period opens; until then it counts as used here.
            new LeavePeriodSlot(null, $nextStart, $nextEnd, LeaveBalance::ENTITLEMENT, $this->balance->deferredDays($user, $nextStart, $nextEnd), $this->pending($user, $nextStart, $nextEnd)),
        ];
    }

    /**
     * Days that the user's pending requests have allocated to parts starting inside these dates. Matching
     * by the part's own start keeps the count right after HR resets a period or changes the anchor.
     */
    private function pending(User $user, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) LeaveRequestAllocation::query()
            ->join('leave_requests as lr', 'lr.id', '=', 'leave_request_allocations.leave_request_id')
            ->where('lr.user_id', $user->getKey())
            ->where('lr.status', RequestStatus::Pending->value)
            ->whereBetween('leave_request_allocations.starts_on', [$start->toDateString(), $end->toDateString()])
            ->sum('leave_request_allocations.days');
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'category' => __('category'),
            'starts_on' => __('start date'),
            'ends_on' => __('end date'),
            'reason' => __('reason'),
        ];
    }
}
