<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\LeaveCategory;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveAllocation;
use App\Support\LeaveBalances;
use App\Support\RequestEvidence;
use App\Support\RequestRules;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitLeaveRequest
{
    private const DEADLOCK_RETRIES = 3;

    /**
     * Create a pending leave request. Every rule that reads stored rows runs under the user row
     * lock, so two requests at once cannot both pass on the same free dates or the same days left.
     *
     * The file is stored first and removed again if the request fails, so no file is left behind.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, array $input, ?UploadedFile $evidence): LeaveRequest
    {
        if (! $user->hasPermissionTo(PermissionName::RequestsCreate)) {
            throw new AuthorizationException;
        }

        $validated = $this->validate($input + ['evidence' => $evidence]);
        $stored = $evidence ? RequestEvidence::store($evidence) : [];

        try {
            return DB::transaction(fn () => $this->create($user, $validated, $stored), self::DEADLOCK_RETRIES);
        } catch (Throwable $exception) {
            RequestEvidence::discard($stored['evidence_path'] ?? null);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $stored
     */
    private function create(User $user, array $validated, array $stored): LeaveRequest
    {
        $user = LeaveBalances::lockUser($user);

        RequestRules::ensureEligible($user);

        $category = LeaveCategory::query()->active()->find($validated['leave_category_id']);

        if (! $category) {
            throw ValidationException::withMessages(['leave_category_id' => 'Choose an available category.']);
        }

        $start = CarbonImmutable::parse($validated['start_date']);
        $end = CarbonImmutable::parse($validated['end_date']);
        $windows = LeaveAllocation::windows($user);

        RequestRules::ensureWithinHorizon($windows, $end);
        RequestRules::ensureNoLeaveOverlap($user, $start, $end, 'start_date');
        RequestRules::ensureNoOvertimeOnDates($user, $start, $end);

        $days = WorkingDays::between($start, $end);

        if ($days === []) {
            throw ValidationException::withMessages(['start_date' => 'This range has no working days.']);
        }

        if ($category->deducts_balance) {
            RequestRules::ensureBalanceCovers($user, $windows, $days);
        }

        $request = new LeaveRequest;
        $request->forceFill([
            'user_id' => $user->getKey(),
            'leave_category_id' => $category->getKey(),
            'start_date' => $start,
            'end_date' => $end,
            'working_days' => count($days),
            'reason' => $validated['reason'],
            ...$stored,
        ])->save();

        if ($category->deducts_balance) {
            $request->allocations()->createMany(array_map(fn (string $day) => ['work_date' => $day], $days));
        }

        AuditLog::record(AuditEvent::LeaveRequestCreated, $user, $request, [
            'category' => $category->code,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'working_days' => count($days),
        ]);

        if ($stored !== []) {
            RequestEvidence::audit($request, $user);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{leave_category_id: int, start_date: string, end_date: string, reason: string}
     */
    private function validate(array $input): array
    {
        return Validator::make($input, [
            'leave_category_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d', Rule::date()->todayOrAfter()],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => RequestEvidence::rules(),
        ], [
            'start_date.after_or_equal' => 'The start date cannot be before today.',
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'evidence.mimetypes' => RequestEvidence::TYPE_MESSAGE,
        ])->validate();
    }
}
