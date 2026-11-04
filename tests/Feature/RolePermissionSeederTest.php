<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_every_role_and_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['HR Admin', 'Approver', 'Employee'],
            Role::pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'users.view', 'users.create', 'users.update', 'users.suspend', 'users.invite', 'departments.manage', 'supervisors.manage', 'leave-balance.view', 'leave-balance.adjust'],
            Permission::pluck('name')->all(),
        );
    }

    public function test_hr_admin_has_every_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertCount(
            count(PermissionName::cases()),
            Role::findByName(RoleName::HrAdmin->value)->permissions,
        );
    }

    public function test_approver_and_employee_can_only_view_the_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach ([RoleName::Approver, RoleName::Employee] as $role) {
            $this->assertSame(
                ['dashboard.view'],
                Role::findByName($role->value)->permissions->pluck('name')->all(),
            );
        }
    }

    public function test_it_can_run_twice_without_duplicates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(RoleName::cases()), Role::count());
        $this->assertSame(count(PermissionName::cases()), Permission::count());
    }

    public function test_the_database_seeder_creates_no_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(count(RoleName::cases()), Role::count());
    }
}
