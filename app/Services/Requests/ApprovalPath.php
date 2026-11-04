<?php

namespace App\Services\Requests;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Whether a user's request has someone to decide it (DECISIONS.md sections 3 and 8). Employee and
 * HR Admin need at least one active supervisor; an Approver needs at least one active HR Admin.
 * The submit buttons and the request services ask the same question.
 */
class ApprovalPath
{
    /**
     * Why this user cannot submit now, or null when they can. Says nothing about other people.
     */
    public function problem(User $user): ?string
    {
        if ($user->status !== UserStatus::Active) {
            return __('Only active accounts can submit requests.');
        }

        if ($user->needsSupervisor()) {
            return $user->hasActiveSupervisor()
                ? null
                : __('You have no active supervisor yet, so you cannot submit requests. Ask HR to assign one.');
        }

        if ($user->requestsGoToHrAdmin()) {
            return User::query()->activeHrAdmin()->exists()
                ? null
                : __('There is no active HR Admin to decide your requests right now. Contact HR.');
        }

        return __('Your account has no role that can submit requests.');
    }
}
