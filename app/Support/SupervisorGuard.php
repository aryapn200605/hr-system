<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Exceptions\SupervisorGuardException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides whether a supervisor relation may exist or end. No action may leave
 * someone who has an active supervisor with none. Callers hold User::withinHrAdminLock(), which
 * serializes every change to supervisors, roles and status, so these checks read settled rows.
 *
 * A suspended employee does not count as affected: they cannot submit anything, and it would
 * otherwise let someone who has left block the suspension of their supervisor.
 */
class SupervisorGuard
{
    /**
     * Refuse when the supervisor leaving altogether, by suspension, role change or deletion, strands anyone.
     */
    public static function ensureSupervisorMayGo(User $supervisor, string $action): void
    {
        static::ensureNobodyStranded($supervisor, null, $action);
    }

    /**
     * Refuse when ending these relations of a supervisor strands anyone.
     *
     * @param  list<int>  $employeeIds
     */
    public static function ensureRelationsMayEnd(User $supervisor, array $employeeIds, string $action): void
    {
        static::ensureNobodyStranded($supervisor, $employeeIds, $action);
    }

    /**
     * Refuse a supervisor who is not an active Approver.
     */
    public static function ensureEligible(User $supervisor, string $action): void
    {
        if (! static::isActiveSupervisor($supervisor)) {
            throw SupervisorGuardException::notEligible($supervisor, $action);
        }
    }

    /**
     * Refuse a link that would make these employees their own supervisor, directly or through others.
     *
     * @param  Collection<int, User>  $employees
     */
    public static function ensureNoLoop(Collection $employees, User $supervisor, string $action): void
    {
        $above = static::everyoneAbove($supervisor);

        foreach ($employees as $employee) {
            if ($employee->is($supervisor)) {
                throw SupervisorGuardException::ownSupervisor($employee, $action);
            }

            if ($above->contains($employee->getKey())) {
                throw SupervisorGuardException::loop($employee, $supervisor, $action);
            }
        }
    }

    /**
     * @param  list<int>|null  $employeeIds  Null means every relation of the supervisor.
     */
    private static function ensureNobodyStranded(User $supervisor, ?array $employeeIds, string $action): void
    {
        // Someone who is not an active supervisor is nobody's active supervisor, so nothing is lost.
        if (! static::isActiveSupervisor($supervisor)) {
            return;
        }

        $supervisorId = $supervisor->getKey();

        $stranded = User::query()
            ->whereHas('supervisors', fn ($query) => $query->whereKey($supervisorId))
            ->when($employeeIds !== null, fn ($query) => $query->whereKey($employeeIds))
            ->where('status', '!=', UserStatus::Suspended)
            ->whereDoesntHave('supervisors', fn ($query) => $query->activeSupervisor()->whereKeyNot($supervisorId))
            ->orderBy('name')
            ->lockForUpdate()
            ->get(['id', 'name']);

        if ($stranded->isNotEmpty()) {
            throw SupervisorGuardException::wouldStrand($action, $supervisor, $stranded);
        }
    }

    private static function isActiveSupervisor(User $user): bool
    {
        return User::query()->whereKey($user->getKey())->activeSupervisor()->exists();
    }

    /**
     * Walk up the relations from one user. Inactive links count, as they return on reactivation.
     *
     * @return Collection<int, int>
     */
    private static function everyoneAbove(User $user): Collection
    {
        $edges = DB::table('employee_supervisor')->get(['employee_id', 'supervisor_id'])->groupBy('employee_id');

        $seen = collect();
        $queue = [$user->getKey()];

        while ($queue !== []) {
            foreach ($edges->get(array_pop($queue), []) as $edge) {
                if (! $seen->contains($edge->supervisor_id)) {
                    $seen->push($edge->supervisor_id);
                    $queue[] = $edge->supervisor_id;
                }
            }
        }

        return $seen;
    }
}
