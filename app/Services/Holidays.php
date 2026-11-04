<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The holiday table kept by HR Admin (DECISIONS.md section 4). Requests freeze their working days at
 * submission, so a change here never alters an existing request.
 */
class Holidays
{
    public const EARLIEST = '2000-01-01';

    /**
     * How many years after the current one a holiday may be entered.
     */
    public const YEARS_AHEAD = 2;

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function create(User $actor, mixed $date, mixed $name): Holiday
    {
        $this->authorize($actor);

        $validated = $this->validate(['date' => $date, 'name' => $name]);

        return $this->unique(fn () => DB::transaction(function () use ($actor, $validated) {
            $holiday = Holiday::query()->create($validated);

            AuditLog::record(AuditEvent::HolidayCreated, $actor, $holiday, $validated);

            return $holiday;
        }));
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function update(User $actor, mixed $id, mixed $date, mixed $name): Holiday
    {
        $this->authorize($actor);

        $validated = $this->validate(['date' => $date, 'name' => $name]);

        return $this->unique(fn () => DB::transaction(function () use ($actor, $id, $validated) {
            $holiday = Holiday::query()->lockForUpdate()->findOrFail($id);
            $before = ['date' => $holiday->date->toDateString(), 'name' => $holiday->name];

            $holiday->fill($validated);

            if ($holiday->isDirty()) {
                $holiday->save();

                AuditLog::record(AuditEvent::HolidayUpdated, $actor, $holiday, ['before' => $before, 'after' => $validated]);
            }

            return $holiday;
        }));
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(User $actor, mixed $id): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $id) {
            $holiday = Holiday::query()->lockForUpdate()->findOrFail($id);
            $holiday->delete();

            AuditLog::record(AuditEvent::HolidayDeleted, $actor, $holiday, [
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ]);
        });
    }

    public static function latest(): string
    {
        return now()->addYears(self::YEARS_AHEAD)->endOfYear()->toDateString();
    }

    /**
     * A taken date is not checked here: the unique index refuses it, and unique() turns that into
     * the validation message, which also covers two admins adding the same date at once.
     *
     * @param  array<string, mixed>  $input
     * @return array{date: string, name: string}
     */
    private function validate(array $input): array
    {
        if (is_string($input['name'])) {
            $input['name'] = trim(preg_replace('/\s+/u', ' ', $input['name']) ?? '');
        }

        return Validator::make($input, [
            'date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST, 'before_or_equal:'.self::latest()],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['date' => __('date'), 'name' => __('holiday name')])->validate();
    }

    /**
     * Report a duplicate date that passed validation in a race as the same message.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['date' => __('There is already a holiday on this date.')]);
        }
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasPermissionTo(PermissionName::HolidaysManage)) {
            throw new AuthorizationException;
        }
    }
}
