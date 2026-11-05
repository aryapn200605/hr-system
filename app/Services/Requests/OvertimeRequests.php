<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Overtime requests (DECISIONS.md sections 4 and 8): whole WIB hours, at most 12 per request, possibly
 * across midnight. One request may end exactly when another begins; only hours that really overlap are
 * refused. The calendar dates an overtime touches run from its start to its last hour worked, so an
 * overtime ending at 00:00 does not touch the next day.
 */
class OvertimeRequests
{
    public const MAX_HOURS = 12;

    public function __construct(
        private Submission $submission,
        private Evidence $evidence,
        private RequestNotifications $notifications,
    ) {}

    /**
     * @param  array{start_at?: mixed, end_at?: mixed, reason?: mixed}  $input  times as "Y-m-d H:i" in WIB
     *
     * @throws AuthorizationException|ValidationException
     */
    public function submit(User $actor, array $input, mixed $evidence = null): OvertimeRequest
    {
        $this->submission->authorizeAndThrottle($actor);

        $reason = $input['reason'] ?? null;

        $validated = Validator::make([
            'start_at' => $input['start_at'] ?? null,
            'end_at' => $input['end_at'] ?? null,
            'reason' => is_string($reason) ? trim($reason) : $reason,
        ], [
            'start_at' => ['required', 'string', 'date_format:Y-m-d H:i'],
            'end_at' => ['required', 'string', 'date_format:Y-m-d H:i'],
            'reason' => $this->submission->reasonRules(),
        ], [], $this->attributeNames())->validate();

        // The leading "!" sets the seconds to zero instead of the current time's (CLAUDE.md lessons).
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['start_at']);
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['end_at']);

        $this->checkTimes($start, $end);

        $this->evidence->validate($evidence);
        $stored = $this->evidence->store($evidence instanceof UploadedFile ? $evidence : null);

        try {
            $request = DB::transaction(fn () => $this->create($actor, $start, $end, $validated['reason'], $stored));
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
    public function cancel(User $actor, mixed $id): OvertimeRequest
    {
        return DB::transaction(function () use ($actor, $id) {
            $request = $this->submission->cancel($actor, OvertimeRequest::class, $id);

            AuditLog::record(AuditEvent::OvertimeRequestCancelled, $actor, $request, [
                'start_at' => $request->start_at->format('Y-m-d H:i'),
                'end_at' => $request->end_at->format('Y-m-d H:i'),
            ]);

            return $request;
        });
    }

    /**
     * For each date this overtime touches, the requester's overtime hours that fall on that date: approved,
     * and pending (this request included). Hours across midnight count on the date they are worked.
     * Shown to the decider instead of a daily limit (DECISIONS.md section 4).
     *
     * @return list<array{date: CarbonImmutable, approved: int, pending: int}>
     */
    public function dayTotals(OvertimeRequest $record): array
    {
        $first = CarbonImmutable::instance($record->first_date);
        $last = CarbonImmutable::instance($record->last_date);

        $others = OvertimeRequest::query()
            ->where('user_id', $record->user_id)
            ->whereIn('status', RequestStatus::blocking())
            ->where('first_date', '<=', $last->toDateString())
            ->where('last_date', '>=', $first->toDateString())
            ->get(['start_at', 'end_at', 'status']);

        $days = [];

        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $totals = ['date' => $date, 'approved' => 0, 'pending' => 0];

            foreach ($others as $other) {
                $from = CarbonImmutable::instance($other->start_at)->max($date);
                $to = CarbonImmutable::instance($other->end_at)->min($date->addDay());

                if ($to->gt($from)) {
                    $totals[$other->status === RequestStatus::Approved ? 'approved' : 'pending'] += (int) $from->diffInHours($to);
                }
            }

            $days[] = $totals;
        }

        return $days;
    }

    /**
     * The last calendar date an overtime touches: the date of its last hour worked.
     */
    public static function lastDate(CarbonImmutable $end): CarbonImmutable
    {
        return $end->subSecond()->startOfDay();
    }

    private function checkTimes(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $errors = [];

        if ($start->minute !== 0) {
            $errors['start_at'] = __('Overtime must start and end on a full hour.');
        }

        if ($end->minute !== 0) {
            $errors['end_at'] = __('Overtime must start and end on a full hour.');
        }

        if ($start->lt(CarbonImmutable::today())) {
            $errors['start_at'] = __('The start date cannot be before today.');
        } elseif ($start->gte(CarbonImmutable::today()->addYear()->addDay())) {
            $errors['start_at'] = __('Overtime can start at most one year ahead.');
        }

        if ($errors === [] && $end->lte($start)) {
            $errors['end_at'] = __('The end must be after the start.');
        } elseif ($errors === [] && $start->diffInHours($end) > self::MAX_HOURS) {
            $errors['end_at'] = __('Overtime can be at most :count hours per request.', ['count' => self::MAX_HOURS]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function create(User $actor, CarbonImmutable $start, CarbonImmutable $end, string $reason, array $stored): OvertimeRequest
    {
        $user = $this->submission->lockRequester($actor);

        $firstDate = $start->startOfDay();
        $lastDate = self::lastDate($end);

        $overlaps = OvertimeRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', RequestStatus::blocking())
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['start_at' => __('These hours overlap another overtime request of yours that is pending or approved.')]);
        }

        $onLeave = LeaveRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', RequestStatus::blocking())
            ->where('starts_on', '<=', $lastDate->toDateString())
            ->where('ends_on', '>=', $firstDate->toDateString())
            ->exists();

        if ($onLeave) {
            throw ValidationException::withMessages(['start_at' => __('This overtime falls on a date of your leave request that is pending or approved.')]);
        }

        $hours = (int) $start->diffInHours($end);

        $request = new OvertimeRequest;
        $request->user_id = $user->getKey();
        $request->start_at = $start;
        $request->end_at = $end;
        $request->hours = $hours;
        $request->first_date = $firstDate;
        $request->last_date = $lastDate;
        $request->reason = $reason;
        $request->status = RequestStatus::Pending;
        $request->forceFill($stored)->save();

        AuditLog::record(AuditEvent::OvertimeRequestCreated, $actor, $request, [
            'start_at' => $start->format('Y-m-d H:i'),
            'end_at' => $end->format('Y-m-d H:i'),
            'hours' => $hours,
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
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return ['start_at' => __('start'), 'end_at' => __('end'), 'reason' => __('reason')];
    }
}
