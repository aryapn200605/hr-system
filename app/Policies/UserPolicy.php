<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Who may do what in User Management. Livewire calls these on every action, and the
 * views ask the same questions, so the screen cannot promise what the server refuses.
 */
class UserPolicy
{
    /**
     * Determine whether the actor may open the user list and a user's details.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::UsersView);
    }

    /**
     * Determine whether the actor may create and invite users.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::UsersCreate)
            && $actor->hasPermissionTo(PermissionName::UsersInvite);
    }

    /**
     * Determine whether the actor may edit a user.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo(PermissionName::UsersUpdate)
            && $this->holdsEverythingOf($actor, $target);
    }

    /**
     * Determine whether the actor may change the username or email. Their own go through My Profile.
     */
    public function updateIdentity(User $actor, User $target): bool
    {
        return $this->update($actor, $target) && ! $actor->is($target);
    }

    /**
     * Determine whether the actor may give this role to someone.
     */
    public function assignRole(User $actor, RoleName $role): bool
    {
        return in_array($role, $this->assignableRoles($actor), true);
    }

    /**
     * Determine whether the actor may give this role to this user.
     */
    public function changeRole(User $actor, User $target, RoleName $role): bool
    {
        return $this->changeAnyRole($actor, $target) && $this->assignRole($actor, $role);
    }

    /**
     * Determine whether the actor may change this user's role at all.
     */
    public function changeAnyRole(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->update($actor, $target);
    }

    /**
     * Determine whether the actor may suspend a user.
     */
    public function suspend(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo(PermissionName::UsersSuspend)
            && ! $actor->is($target)
            && $this->holdsEverythingOf($actor, $target);
    }

    /**
     * Determine whether the actor may reactivate a user.
     */
    public function reactivate(User $actor, User $target): bool
    {
        return $this->suspend($actor, $target);
    }

    /**
     * Determine whether the actor may resend an invitation to a user.
     */
    public function resendInvitation(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo(PermissionName::UsersInvite)
            && $target->status === UserStatus::Pending
            && $this->holdsEverythingOf($actor, $target);
    }

    /**
     * Determine whether the actor may change who supervises whom.
     */
    public function manageSupervisors(User $actor): bool
    {
        return $actor->hasPermissionTo(PermissionName::SupervisorsManage);
    }

    /**
     * The roles whose every permission the actor already holds, read from the database.
     * Nobody can hand out more than they have, whatever the role is called.
     *
     * @return list<RoleName>
     */
    public function assignableRoles(User $actor): array
    {
        $held = $actor->getAllPermissions()->pluck('name');
        $roles = Role::query()->with('permissions')->get()->keyBy('name');

        return array_values(array_filter(RoleName::cases(), function (RoleName $role) use ($roles, $held) {
            $permissions = $roles->get($role->value)?->permissions;

            return $permissions !== null && $permissions->pluck('name')->diff($held)->isEmpty();
        }));
    }

    /**
     * Determine whether the actor holds every permission of the target, so that
     * nobody can act on an account with more power than their own.
     */
    private function holdsEverythingOf(User $actor, User $target): bool
    {
        return $target->getAllPermissions()->pluck('name')
            ->diff($actor->getAllPermissions()->pluck('name'))
            ->isEmpty();
    }
}
