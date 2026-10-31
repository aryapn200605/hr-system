<?php

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateEmployment
{
    private const EARLIEST_DATE = '1950-01-01';

    /**
     * Change a user's department and join date. A deactivated department cannot be
     * chosen, but a user already in one can keep it while other details change.
     *
     * @param  array{department_id: mixed, joined_at: mixed}  $input
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, array $input, User $actor): void
    {
        if (Gate::forUser($actor)->denies('update', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($user, $input, $actor) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $locked->forceFill($this->validate($input, $locked));

            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return;
            }

            $locked->save();

            AuditLog::record(AuditEvent::UserUpdated, $actor, $locked, ['fields' => $changed]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{department_id: ?int, joined_at: ?string}
     */
    private function validate(array $input, User $user): array
    {
        return Validator::make(array_map(fn (mixed $value) => $value === '' ? null : $value, $input), [
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists(Department::class, 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->orWhere('id', $user->department_id)),
            ],
            'joined_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST_DATE],
        ], [
            'department_id.exists' => 'Choose an active department.',
        ])->validate();
    }
}
