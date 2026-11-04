<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Exceptions\SupervisorGuardException;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one rule about supervisors that every entry point shares: no action may leave a user who has
 * an active supervisor now with none. An active supervisor is a linked user who is ACTIVE and holds
 * the Approver role. Suspended subordinates are not counted.
 *
 * Callers hold User::withinAccountLock(), which serializes every change to links, statuses and roles,
 * so what check() reads cannot change under it.
 */
class SupervisorGuard
{
    /**
     * Run a change that may remove supervision from the subordinates of these supervisors, and refuse
     * it if one of them, who had an active supervisor before, has none after.
     *
     * Throwing inside the caller's transaction rolls the change back.
     *
     * @template TReturn
     *
     * @param  list<int>  $supervisorIds
     * @param  Closure(): TReturn  $change
     * @return TReturn
     *
     * @throws SupervisorGuardException
     */
    public function check(array $supervisorIds, Closure $change): mixed
    {
        $watched = $this->supervisedSubordinatesOf($supervisorIds);

        $result = $change();

        if ($watched !== []) {
            $stillSupervised = $this->activeSupervisors()
                ->whereIn('su.subordinate_id', $watched)
                ->distinct()
                ->pluck('su.subordinate_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $orphans = array_values(array_diff($watched, $stillSupervised));

            if ($orphans !== []) {
                throw new SupervisorGuardException(
                    User::query()->whereIn('id', $orphans)->orderBy('name')->get(['id', 'name']),
                    $supervisorIds,
                );
            }
        }

        return $result;
    }

    /**
     * Run an entry point and audit a refusal by check(). The audit row is written after the transaction
     * that held the change has rolled back, so the refusal is kept while the change is not. A nested call
     * (suspend() saving the user) writes one too, but inside the outer transaction, which then rolls it
     * back with everything else: only the outermost row stays.
     *
     * Entry points must not run inside a transaction of their caller, or that transaction decides the
     * fate of the audit row too.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $run
     * @return TReturn
     */
    public function auditing(string $action, ?User $actor, ?Model $subject, Closure $run): mixed
    {
        try {
            return $run();
        } catch (SupervisorGuardException $exception) {
            AuditLog::record(AuditEvent::SupervisorGuardRefused, $actor, $subject, [
                'action' => $action,
                'supervisor_ids' => $exception->supervisorIds,
                'affected_ids' => $exception->affected->pluck('id')->all(),
            ]);

            throw $exception;
        }
    }

    /**
     * Links whose supervisor is active, aliased `su`. Every linked supervisor is an Approver: a link is
     * made only to an active Approver, and losing the role removes the user's links (User::changeRole()).
     */
    public function activeSupervisors(): Builder
    {
        return DB::table('supervisor_user as su')
            ->join('users as sup', 'sup.id', '=', 'su.supervisor_id')
            ->where('sup.status', UserStatus::Active->value);
    }

    /**
     * Subordinates of these supervisors who are not suspended and have an active supervisor now.
     *
     * @param  list<int>  $supervisorIds
     * @return list<int>
     */
    private function supervisedSubordinatesOf(array $supervisorIds): array
    {
        $subordinates = DB::table('supervisor_user as su')
            ->join('users as sub', 'sub.id', '=', 'su.subordinate_id')
            ->whereIn('su.supervisor_id', $supervisorIds)
            ->where('sub.status', '!=', UserStatus::Suspended->value)
            ->distinct()
            ->pluck('su.subordinate_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($subordinates === []) {
            return [];
        }

        return $this->activeSupervisors()
            ->whereIn('su.subordinate_id', $subordinates)
            ->distinct()
            ->pluck('su.subordinate_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
