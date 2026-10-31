<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Exceptions\AccountProtectionException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Every write to employee_supervisor. A null actor means the system itself, such as a console command.
 */
class SupervisorRelations
{
    /**
     * Give an employee one more supervisor.
     *
     * @throws AuthorizationException
     */
    public static function assign(User $employee, User $supervisor, ?User $actor = null): void
    {
        static::authorize($actor);

        User::withinHrAdminLock(function () use ($employee, $supervisor, $actor) {
            SupervisorGuard::ensureNoLoop(collect([$employee]), $supervisor, 'assign');
            SupervisorGuard::ensureEligible($supervisor, 'assign');

            if ($employee->supervisors()->whereKey($supervisor->getKey())->exists()) {
                throw AccountProtectionException::alreadySupervisor();
            }

            static::link($employee->getKey(), $supervisor->getKey(), $actor);

            AuditLog::record(AuditEvent::SupervisorAssigned, $actor, $employee, ['supervisor_id' => $supervisor->getKey()]);
        });
    }

    /**
     * End one relation.
     *
     * @throws AuthorizationException
     */
    public static function remove(User $employee, User $supervisor, ?User $actor = null): void
    {
        static::authorize($actor);

        User::withinHrAdminLock(function () use ($employee, $supervisor, $actor) {
            SupervisorGuard::ensureRelationsMayEnd($supervisor, [$employee->getKey()], 'remove');

            if ($employee->supervisors()->detach($supervisor->getKey()) === 0) {
                throw AccountProtectionException::notSupervisor();
            }

            AuditLog::record(AuditEvent::SupervisorRemoved, $actor, $employee, ['supervisor_id' => $supervisor->getKey()]);
        });
    }

    /**
     * Move subordinates from one supervisor to another, all or none, and return how many moved.
     *
     * @param  list<int>|null  $employeeIds  Null means all of them.
     *
     * @throws AuthorizationException
     */
    public static function move(User $from, User $to, ?array $employeeIds, ?User $actor = null): int
    {
        static::authorize($actor);

        return User::withinHrAdminLock(function () use ($from, $to, $employeeIds, $actor) {
            if ($from->is($to)) {
                throw AccountProtectionException::sameSupervisor();
            }

            $subordinates = $from->subordinates()->lockForUpdate()->get(['users.id', 'users.name']);
            $employees = $employeeIds === null ? $subordinates : $subordinates->whereIn('id', $employeeIds)->values();

            if ($employees->isEmpty() || ($employeeIds !== null && $employees->count() !== count(array_unique($employeeIds)))) {
                throw AccountProtectionException::nothingToMove($from);
            }

            SupervisorGuard::ensureEligible($to, 'move');
            SupervisorGuard::ensureNoLoop($employees, $to, 'move');

            $ids = $employees->pluck('id')->all();
            $alreadyLinked = $to->subordinates()->whereKey($ids)->pluck('users.id');

            foreach ($employees->whereNotIn('id', $alreadyLinked) as $employee) {
                static::link($employee->getKey(), $to->getKey(), $actor);
            }

            // Runs with the new links in place, so it sees what the move really leaves behind.
            SupervisorGuard::ensureRelationsMayEnd($from, $ids, 'move');

            DB::table('employee_supervisor')
                ->where('supervisor_id', $from->getKey())
                ->whereIn('employee_id', $ids)
                ->delete();

            AuditLog::record(AuditEvent::SupervisorsMoved, $actor, $from, [
                'to_supervisor_id' => $to->getKey(),
                'employee_ids' => $ids,
                'scope' => $employeeIds === null ? 'all' : 'selected',
            ]);

            return count($ids);
        });
    }

    private static function link(int $employeeId, int $supervisorId, ?User $actor): void
    {
        DB::table('employee_supervisor')->insert([
            'employee_id' => $employeeId,
            'supervisor_id' => $supervisorId,
            'created_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    private static function authorize(?User $actor): void
    {
        if ($actor && ! $actor->hasPermissionTo(PermissionName::SupervisorsManage)) {
            throw new AuthorizationException;
        }
    }
}
