<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\LeaveCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Users who can submit requests: an Employee or HR Admin with an active supervisor, an Approver
 * while an HR Admin is active. Needs RefreshDatabase or the caller's own cleanup.
 */
trait CreatesRequesters
{
    protected function seedRequestData(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveCategorySeeder::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function member(RoleName $role, array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create($attributes);
    }

    protected function supervise(User $supervisor, User $subordinate): void
    {
        DB::table('supervisor_user')->insert([
            'supervisor_id' => $supervisor->id,
            'subordinate_id' => $subordinate->id,
            'created_at' => now(),
        ]);
    }

    /**
     * An Employee (or HR Admin) with one active supervisor.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function supervisedMember(RoleName $role = RoleName::Employee, array $attributes = []): User
    {
        $user = $this->member($role, $attributes);
        $this->supervise($this->member(RoleName::Approver), $user);

        return $user;
    }
}
