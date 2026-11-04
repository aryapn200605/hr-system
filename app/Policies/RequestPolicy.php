<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;

/**
 * Who may do what with leave and overtime requests. In Phase 9 only the requester sees a request;
 * Phase 10 adds the people who may decide it.
 */
class RequestPolicy
{
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

    public function downloadEvidence(User $actor, LeaveRequest|OvertimeRequest $request): bool
    {
        return $this->view($actor, $request) && $request->hasEvidence();
    }
}
