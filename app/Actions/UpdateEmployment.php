<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Change a user's department and join date. A department must be active to be given anew; an inactive
 * one a user already has may stay. The join date is for information only: leave follows the anchor date.
 */
class UpdateEmployment
{
    public const EARLIEST_JOIN_DATE = '1950-01-01';

    public function __construct(private UserPolicy $policy) {}

    /**
     * @param  array{department_id: mixed, join_date: mixed}  $input
     * @return list<string> the fields that changed
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(User $user, array $input, User $actor): array
    {
        if (! $this->policy->update($actor, $user)) {
            throw new AuthorizationException;
        }

        $input = array_map(fn ($value) => $value === '' ? null : $value, $input);

        $validated = Validator::make($input, [
            'department_id' => ['nullable', 'integer', 'min:1'],
            'join_date' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST_JOIN_DATE, 'before_or_equal:'.today()->addYear()->toDateString()],
        ], [], [
            'department_id' => __('department'),
            'join_date' => __('join date'),
        ])->validate();

        return DB::transaction(function () use ($user, $validated, $actor) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $departmentId = $validated['department_id'] === null ? null : (int) $validated['department_id'];

            if ($departmentId !== null && $departmentId !== $locked->department_id) {
                // Shared lock: a deactivation or delete waits for this, and this waits for them.
                $department = Department::query()->sharedLock()->find($departmentId);

                if (! $department?->is_active) {
                    throw ValidationException::withMessages(['department_id' => __('Choose an active department.')]);
                }
            }

            $locked->forceFill(['department_id' => $departmentId, 'join_date' => $validated['join_date']]);
            $changed = array_keys($locked->getDirty());

            if ($changed !== []) {
                $locked->save();
                $user->setRawAttributes($locked->getAttributes(), true);

                AuditLog::record(AuditEvent::EmploymentUpdated, $actor, $user, ['fields' => $changed]);
            }

            return $changed;
        });
    }
}
