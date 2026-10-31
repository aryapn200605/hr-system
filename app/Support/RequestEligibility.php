<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Whether someone can submit a request at all. The pages show this reason on a disabled
 * button and the server refuses with it, so the screen cannot promise what the server refuses.
 */
class RequestEligibility
{
    /**
     * Get what stops this user from submitting, or null when nothing does.
     */
    public static function blockedReason(User $user): ?string
    {
        if ($user->status !== UserStatus::Active) {
            return 'Your account is not active, so you cannot submit requests.';
        }

        $role = RoleName::tryFrom((string) $user->roleName());

        if (! $role) {
            return 'Your account has no role, so nobody can decide your requests. Ask an HR Admin to set one.';
        }

        if ($role->needsSupervisor()) {
            return $user->supervisors()->activeSupervisor()->exists()
                ? null
                : 'You have no active supervisor, so nobody can decide your requests. Ask an HR Admin to assign one.';
        }

        return User::query()->activeHrAdmin()->exists()
            ? null
            : 'There is no active HR Admin to decide your requests.';
    }
}
