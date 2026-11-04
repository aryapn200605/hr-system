<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The department master list. Every change locks the department row, which assigning a department
 * to an employee also does, so a department cannot be assigned while it is being deactivated or deleted.
 */
class Departments
{
    /**
     * @throws AuthorizationException|ValidationException
     */
    public function create(User $actor, mixed $name): Department
    {
        $this->authorize($actor);

        $validated = $this->validate(['name' => $name]);

        return $this->unique($validated, function () use ($actor, $validated) {
            return DB::transaction(function () use ($actor, $validated) {
                $department = Department::query()->create(['name' => $validated['name'], 'is_active' => true]);

                AuditLog::record(AuditEvent::DepartmentCreated, $actor, $department, ['name' => $department->name]);

                return $department;
            });
        });
    }

    /**
     * Rename a department, and activate or deactivate it.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(User $actor, mixed $id, mixed $name, mixed $active): Department
    {
        $this->authorize($actor);

        $validated = $this->validate(['name' => $name, 'active' => $active], $id);

        return $this->unique($validated, function () use ($actor, $id, $validated) {
            return DB::transaction(function () use ($actor, $id, $validated) {
                $department = Department::query()->lockForUpdate()->findOrFail($id);

                $department->fill(['name' => $validated['name'], 'is_active' => (bool) $validated['active']]);
                $changed = array_keys($department->getDirty());

                if ($changed !== []) {
                    $department->save();

                    AuditLog::record(AuditEvent::DepartmentUpdated, $actor, $department, [
                        'fields' => $changed,
                        'name' => $department->name,
                        'is_active' => $department->is_active,
                    ]);
                }

                return $department;
            });
        });
    }

    /**
     * Delete a department nobody has. A used one can only be deactivated: the foreign key refuses it.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function delete(User $actor, mixed $id): void
    {
        $this->authorize($actor);

        try {
            DB::transaction(function () use ($actor, $id) {
                $department = Department::query()->lockForUpdate()->findOrFail($id);

                $department->delete();

                AuditLog::record(AuditEvent::DepartmentDeleted, $actor, $department, ['name' => $department->name]);
            });
        } catch (QueryException $exception) {
            // The restricting foreign key is the check: a department that any user still has cannot go.
            if (($exception->errorInfo[1] ?? null) === 1451) {
                throw $this->inUse();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, mixed $id = null): array
    {
        if (is_string($input['name'])) {
            $input['name'] = trim(preg_replace('/\s+/u', ' ', $input['name']) ?? '');
        }

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($id)],
        ];

        if (array_key_exists('active', $input)) {
            $rules['active'] = ['required', 'boolean'];
        }

        return Validator::make($input, $rules, [], ['name' => __('department name')])->validate();
    }

    /**
     * Report a duplicate that passed validation in a race as the same validation message.
     *
     * @template TReturn
     *
     * @param  array<string, mixed>  $validated
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private function unique(array $validated, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => __('department name')])]);
        }
    }

    private function inUse(): ValidationException
    {
        return ValidationException::withMessages(['department' => __('This department is still assigned to employees. Deactivate it instead.')]);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasPermissionTo(PermissionName::DepartmentsManage)) {
            throw new AuthorizationException;
        }
    }
}
