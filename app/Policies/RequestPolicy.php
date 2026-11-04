<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Reports\Visibility;
use App\Services\Requests\Deciders;

/**
 * Who may do what with leave and overtime requests. The requester sees their own requests in My
 * requests; the people who may decide a request now, and whoever decided it, see it in Approvals.
 */
class RequestPolicy
{
    public function __construct(private Deciders $deciders, private Visibility $visibility) {}

    /**
     * Determine whether the actor may open My requests and submit. Whether the request has someone
     * to decide it is App\Services\Requests\ApprovalPath's question.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::RequestsSubmit);
    }

    public function view(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        return $this->create($actor) && $request->user_id === $actor->getKey();
    }

    public function cancel(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        return $this->view($actor, $request) && $request->status === RequestStatus::Pending;
    }

    /**
     * Determine whether the actor may open the Approvals inbox.
     */
    public function decideAny(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::RequestsDecide);
    }

    /**
     * Determine whether the actor may open the request in Approvals: they may decide it now (whatever
     * its status, so a second supervisor learns who decided first), or they decided it themself.
     */
    public function review(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        if (! $this->decideAny($actor)) {
            return false;
        }

        return $request->decided_by === $actor->getKey()
            || $this->deciders->allows($actor, $request->user()->with('roles')->firstOrFail());
    }

    /**
     * Determine whether the actor may open request history and recap. What they see there is
     * App\Services\Reports\Visibility's question.
     */
    public function viewHistory(User $actor): bool
    {
        return $this->visibility->seesAnything($actor);
    }

    public function downloadEvidence(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        return $request->hasEvidence() && ($this->view($actor, $request) || $this->review($actor, $request));
    }
}
