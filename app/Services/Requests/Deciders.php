<?php

namespace App\Services\Requests;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who may decide whose requests (DECISIONS.md section 3). The one rule, used by the inbox, the pages,
 * the evidence download and the decision itself:
 *
 * - an Employee's or HR Admin's request: a supervisor linked to the requester, who is an ACTIVE Approver;
 * - an Approver's request: any ACTIVE HR Admin;
 * - never without the requests.decide permission.
 *
 * Nobody can decide their own request: the role that decides is never the requester's role (an Approver
 * decides Employees and HR Admins, an HR Admin decides Approvers), and a user has one role.
 *
 * It reads the users as they are now, never as they were at submission. The decision service passes
 * users freshly read inside its transaction.
 */
class Deciders
{
    public function allows(User $decider, User $requester): bool
    {
        if ($decider->status !== UserStatus::Active || ! $decider->hasPermissionTo(PermissionName::RequestsDecide)) {
            return false;
        }

        // Links exist only to Approvers: one is made only to an active Approver, and losing the role removes them.
        if ($requester->needsSupervisor()) {
            return $this->links($decider)->where('subordinate_id', $requester->getKey())->exists();
        }

        if ($requester->requestsGoToHrAdmin()) {
            return $decider->isHrAdmin();
        }

        return false;
    }

    /**
     * Everyone who may decide this requester's requests now: the candidates (the linked supervisors of an
     * Employee or HR Admin, the active HR Admins otherwise) filtered through allows(), so this list never
     * differs from who may actually decide.
     *
     * @return Collection<int, User>
     */
    public function of(User $requester): Collection
    {
        // The candidates narrow the rows read; allows() decides. A requester without a path gets nobody.
        $candidates = $requester->needsSupervisor()
            ? User::query()->whereIn('users.id', DB::table('supervisor_user')->where('subordinate_id', $requester->getKey())->select('supervisor_id'))
            : User::query()->activeHrAdmin();

        return $candidates->with('roles')->orderBy('users.id')->get()
            ->filter(fn (User $decider) => $this->allows($decider, $requester))
            ->values();
    }

    /**
     * The users whose requests this decider may decide now, as a query of user IDs for a whereIn().
     */
    public function requesterIds(User $decider): Builder
    {
        $query = User::query()->select('users.id');

        if ($decider->status !== UserStatus::Active || ! $decider->hasPermissionTo(PermissionName::RequestsDecide)) {
            return $query->whereRaw('1 = 0');
        }

        if ($decider->isApprover()) {
            return $query->requestingFromSupervisor()
                ->whereIn('users.id', $this->links($decider)->select('subordinate_id'));
        }

        if ($decider->isHrAdmin()) {
            return $query->requestingFromHrAdmin();
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * How many pending requests wait for this decider, for the menu.
     */
    public function waitingCount(User $decider): int
    {
        $count = 0;

        foreach ([LeaveRequest::class, OvertimeRequest::class] as $model) {
            $count += $model::query()
                ->where('status', RequestStatus::Pending->value)
                ->whereIn('user_id', $this->requesterIds($decider))
                ->count();
        }

        return $count;
    }

    private function links(User $supervisor): QueryBuilder
    {
        return DB::table('supervisor_user')->where('supervisor_id', $supervisor->getKey());
    }
}
