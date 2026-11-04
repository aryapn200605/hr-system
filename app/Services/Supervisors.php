<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who supervises whom. Every change runs under User::withinAccountLock(), rechecks the accounts as they
 * are in the database, and goes through SupervisorGuard. Refusals of the guard are audited.
 */
class Supervisors
{
    public function __construct(private SupervisorGuard $guard, private UserPolicy $policy) {}

    /**
     * Link a supervisor to a subordinate. The supervisor must be an active Approver, not the
     * subordinate, and not someone the subordinate already supervises, directly or not.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function attach(User $actor, User $subordinate, mixed $supervisorId): void
    {
        $this->authorize($actor, $subordinate);

        User::withinAccountLock(function () use ($actor, $subordinate, $supervisorId) {
            $supervisor = $this->eligibleSupervisor($supervisorId, 'supervisor');

            if ($supervisor->is($subordinate)) {
                throw ValidationException::withMessages(['supervisor' => __('A user cannot supervise themselves.')]);
            }

            if ($this->linked($supervisor->getKey(), $subordinate->getKey())) {
                throw ValidationException::withMessages(['supervisor' => __(':name is already a supervisor of this user.', ['name' => $supervisor->name])]);
            }

            if ($this->wouldCycle($supervisor->getKey(), $subordinate->getKey())) {
                throw ValidationException::withMessages(['supervisor' => __(':name is supervised by this user, directly or through others, so cannot supervise them.', ['name' => $supervisor->name])]);
            }

            DB::table('supervisor_user')->insert([
                'supervisor_id' => $supervisor->getKey(),
                'subordinate_id' => $subordinate->getKey(),
                'created_at' => now(),
            ]);

            AuditLog::record(AuditEvent::SupervisorAttached, $actor, $subordinate, ['supervisor_id' => $supervisor->getKey()]);
        });
    }

    /**
     * Remove one link, unless it is the subordinate's last active supervisor.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function detach(User $actor, User $subordinate, mixed $supervisorId): void
    {
        $this->authorize($actor, $subordinate);

        $supervisorId = $this->id($supervisorId, 'supervisor');

        $this->guard->auditing('detach', $actor, $subordinate, fn () => User::withinAccountLock(function () use ($actor, $subordinate, $supervisorId) {
            $this->guard->check([$supervisorId], function () use ($subordinate, $supervisorId) {
                $removed = DB::table('supervisor_user')
                    ->where('supervisor_id', $supervisorId)
                    ->where('subordinate_id', $subordinate->getKey())
                    ->delete();

                if ($removed === 0) {
                    throw ValidationException::withMessages(['supervisor' => __('This supervisor was already removed.')]);
                }
            });

            AuditLog::record(AuditEvent::SupervisorDetached, $actor, $subordinate, ['supervisor_id' => $supervisorId]);
        }));
    }

    /**
     * Move subordinates of one supervisor to another, all of them (null) or the chosen ones, in one
     * transaction. A subordinate who already has the new supervisor just loses the old one.
     *
     * @param  list<mixed>|null  $subordinateIds
     * @return int how many subordinates moved
     *
     * @throws AuthorizationException|ValidationException
     */
    public function move(User $actor, User $from, mixed $toId, ?array $subordinateIds): int
    {
        if (! $this->policy->manageSupervisors($actor, $from)) {
            throw new AuthorizationException;
        }

        $chosen = $subordinateIds === null ? null : array_values(array_unique(array_map(fn ($id) => $this->id($id, 'selection'), $subordinateIds)));

        return $this->guard->auditing('move', $actor, $from, fn () => User::withinAccountLock(function () use ($actor, $from, $toId, $chosen) {
            $to = $this->eligibleSupervisor($toId, 'target');

            if ($to->is($from)) {
                throw ValidationException::withMessages(['target' => __('Choose a different supervisor to move to.')]);
            }

            $current = DB::table('supervisor_user')->where('supervisor_id', $from->getKey())->pluck('subordinate_id')->map(fn ($id) => (int) $id)->all();
            $moving = $chosen ?? $current;

            if ($moving === []) {
                throw ValidationException::withMessages(['selection' => __('Choose at least one subordinate to move.')]);
            }

            if (array_diff($moving, $current) !== []) {
                throw ValidationException::withMessages(['selection' => __('The list of subordinates changed meanwhile. Reload the page and try again.')]);
            }

            $subordinates = User::query()->whereIn('id', $moving)->get(['id', 'name']);

            foreach ($subordinates as $subordinate) {
                if ($subordinate->is($to)) {
                    throw ValidationException::withMessages(['selection' => __(':name cannot be moved to themselves as supervisor.', ['name' => $subordinate->name])]);
                }

                if ($this->wouldCycle($to->getKey(), $subordinate->getKey())) {
                    throw ValidationException::withMessages(['selection' => __(':name supervises :target, directly or through others, so cannot be moved under them.', ['name' => $subordinate->name, 'target' => $to->name])]);
                }
            }

            $this->guard->check([$from->getKey()], function () use ($from, $to, $moving) {
                DB::table('supervisor_user')->where('supervisor_id', $from->getKey())->whereIn('subordinate_id', $moving)->delete();

                DB::table('supervisor_user')->insertOrIgnore(array_map(fn (int $id) => [
                    'supervisor_id' => $to->getKey(),
                    'subordinate_id' => $id,
                    'created_at' => now(),
                ], $moving));
            });

            AuditLog::record(AuditEvent::SupervisorsMoved, $actor, $from, [
                'from_id' => $from->getKey(),
                'to_id' => $to->getKey(),
                'subordinate_ids' => $moving,
            ]);

            return count($moving);
        }));
    }

    /**
     * Load a user who can supervise now: ACTIVE and Approver. The caller holds the account lock.
     */
    private function eligibleSupervisor(mixed $id, string $field): User
    {
        $supervisor = User::query()->activeApprover()->find($this->id($id, $field));

        if (! $supervisor) {
            throw ValidationException::withMessages([$field => __('Choose an active Approver as supervisor.')]);
        }

        return $supervisor;
    }

    private function linked(int $supervisorId, int $subordinateId): bool
    {
        return DB::table('supervisor_user')->where('supervisor_id', $supervisorId)->where('subordinate_id', $subordinateId)->exists();
    }

    /**
     * Whether making $supervisorId supervise $subordinateId closes a loop: the subordinate is already
     * above the supervisor, directly or through others. UNION, not UNION ALL, so the walk ends.
     */
    private function wouldCycle(int $supervisorId, int $subordinateId): bool
    {
        return (int) DB::selectOne(
            'WITH RECURSIVE above (id) AS (
                SELECT supervisor_id FROM supervisor_user WHERE subordinate_id = ?
                UNION
                SELECT su.supervisor_id FROM supervisor_user su JOIN above ON su.subordinate_id = above.id
            ) SELECT EXISTS (SELECT 1 FROM above WHERE id = ?) AS found',
            [$supervisorId, $subordinateId],
        )->found === 1;
    }

    /**
     * Turn a client value into a user ID, or refuse it as a validation error on that field.
     */
    private function id(mixed $value, string $field): int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($id === false) {
            throw ValidationException::withMessages([$field => __('Choose a valid user.')]);
        }

        return $id;
    }

    private function authorize(User $actor, User $subordinate): void
    {
        if (! $this->policy->manageSupervisors($actor, $subordinate)) {
            throw new AuthorizationException;
        }
    }
}
