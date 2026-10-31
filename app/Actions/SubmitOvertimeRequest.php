<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\RequestEvidence;
use App\Support\RequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitOvertimeRequest
{
    private const FORMAT = 'Y-m-d\TH:i';

    private const DEADLOCK_RETRIES = 3;

    /**
     * Create a pending overtime request, under the same user row lock as leave requests,
     * so a leave and an overtime request made at once cannot miss each other.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, array $input, ?UploadedFile $evidence): OvertimeRequest
    {
        if (! $user->hasPermissionTo(PermissionName::RequestsCreate)) {
            throw new AuthorizationException;
        }

        $validated = $this->validate($input + ['evidence' => $evidence]);

        $start = CarbonImmutable::createFromFormat(self::FORMAT, $validated['start_at']);
        $end = CarbonImmutable::createFromFormat(self::FORMAT, $validated['end_at']);

        $this->ensureShape($start, $end);

        $stored = $evidence ? RequestEvidence::store($evidence) : [];

        try {
            return DB::transaction(fn () => $this->create($user, $start, $end, $validated['reason'], $stored), self::DEADLOCK_RETRIES);
        } catch (Throwable $exception) {
            RequestEvidence::discard($stored['evidence_path'] ?? null);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function create(User $user, CarbonImmutable $start, CarbonImmutable $end, string $reason, array $stored): OvertimeRequest
    {
        $user = LeaveBalances::lockUser($user);

        RequestRules::ensureEligible($user);
        RequestRules::ensureNoOvertimeOverlap($user, $start, $end);
        RequestRules::ensureNoLeaveOnOvertimeDates($user, $start, $end);

        $hours = (int) $start->diffInHours($end);

        $request = new OvertimeRequest;
        $request->forceFill([
            'user_id' => $user->getKey(),
            'start_at' => $start,
            'end_at' => $end,
            'hours' => $hours,
            'reason' => $reason,
            ...$stored,
        ])->save();

        AuditLog::record(AuditEvent::OvertimeRequestCreated, $user, $request, [
            'start_at' => $start->format('Y-m-d H:i'),
            'end_at' => $end->format('Y-m-d H:i'),
            'hours' => $hours,
        ]);

        if ($stored !== []) {
            RequestEvidence::audit($request, $user);
        }

        return $request;
    }

    /**
     * Refuse times that are wrong whatever else is stored.
     */
    private function ensureShape(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($start->minute !== 0) {
            throw ValidationException::withMessages(['start_at' => 'Overtime starts on the hour, such as 18:00.']);
        }

        if ($end->minute !== 0) {
            throw ValidationException::withMessages(['end_at' => 'Overtime ends on the hour, such as 21:00.']);
        }

        if ($start->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['start_at' => 'The start date cannot be before today.']);
        }

        if (! $end->greaterThan($start)) {
            throw ValidationException::withMessages(['end_at' => 'The end must be after the start.']);
        }

        if ($start->diffInHours($end) > RequestRules::MAX_OVERTIME_HOURS) {
            throw ValidationException::withMessages(['end_at' => 'One request can cover at most '.RequestRules::MAX_OVERTIME_HOURS.' hours.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{start_at: string, end_at: string, reason: string}
     */
    private function validate(array $input): array
    {
        return Validator::make($input, [
            'start_at' => ['required', 'date_format:'.self::FORMAT],
            'end_at' => ['required', 'date_format:'.self::FORMAT],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => RequestEvidence::rules(),
        ], ['evidence.mimetypes' => RequestEvidence::TYPE_MESSAGE])->validate();
    }
}
