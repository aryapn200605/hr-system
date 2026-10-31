<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Departments\Index;
use App\Livewire\Supervisors\Move;
use App\Livewire\Users\LeaveBalance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each page has two independent checks: route middleware and boot(). Requests through the
 * router cannot tell them apart, so each one is checked here on its own.
 */
class RouteAndBootChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->employee = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
    }

    public function test_boot_refuses_without_the_router(): void
    {
        Livewire::actingAs($this->employee)->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->employee)->test(Move::class)->assertForbidden();
        Livewire::actingAs($this->employee)->test(LeaveBalance::class, ['user' => (string) $this->employee->id])->assertForbidden();
    }

    public function test_every_route_carries_its_permission(): void
    {
        $routes = [
            'departments.index' => PermissionName::DepartmentsManage,
            'supervisors.move' => PermissionName::SupervisorsManage,
            'users.leave-balance' => PermissionName::LeaveBalanceView,
            'users.employment' => PermissionName::UsersView,
        ];

        foreach ($routes as $name => $permission) {
            $this->assertContains('can:'.$permission->value, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }
}
