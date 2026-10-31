<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;

/**
 * Who may reach a leave or overtime request. Deciding them comes in a later stage.
 */
class RequestPolicy
{
    /**
     * Determine whether the actor may open or cancel a request. Only its owner can, for now.
     */
    public function own(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        return $request->user_id === $actor->getKey();
    }

    /**
     * Determine whether the actor may download the evidence: the owner, anyone holding the view-any
     * permission, or an active supervisor of the requester. HR Admins decide for an Approver
     * without any relation, so a supervisor link gives no access to an Approver's files.
     */
    public function viewEvidence(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        if ($this->own($actor, $request) || $actor->hasPermissionTo(PermissionName::RequestsViewAny)) {
            return true;
        }

        $requester = $request->user;

        return RoleName::tryFrom((string) $requester->roleName())?->needsSupervisor() === true
            && $requester->supervisors()->activeSupervisor()->whereKey($actor->getKey())->exists();
    }
}
