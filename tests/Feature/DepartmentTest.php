<?php

namespace Tests\Feature;

use App\Actions\UpdateEmployment;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Departments\Index;
use App\Livewire\Users\Show;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\Departments;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->hrAdmin();
    }

    private function department(string $name = 'Finance', bool $active = true): Department
    {
        return Department::query()->create(['name' => $name, 'is_active' => $active]);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    public function test_an_admin_creates_renames_deactivates_and_deletes_a_department(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openCreate')
            ->set('name', '  Human   Resources ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $department = Department::query()->sole();
        $this->assertSame('Human Resources', $department->name);
        $this->assertTrue($department->is_active);

        $component->call('openEdit', $department->id)
            ->set('name', 'People')
            ->set('active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('People', $department->fresh()->name);
        $this->assertFalse($department->fresh()->is_active);

        $component->call('openDelete', $department->id)->call('delete')->assertSet('notice', '');

        $this->assertModelMissing($department);
        $this->assertSame(
            [AuditEvent::DepartmentCreated->value, AuditEvent::DepartmentUpdated->value, AuditEvent::DepartmentDeleted->value],
            AuditLog::query()->where('subject_type', (new Department)->getMorphClass())->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(['name', 'is_active'], AuditLog::query()->where('event', AuditEvent::DepartmentUpdated->value)->sole()->metadata['fields']);
    }

    public function test_names_are_required_unique_ignoring_case_and_limited(): void
    {
        $this->department('Finance');
        $component = Livewire::actingAs($this->admin)->test(Index::class)->call('openCreate');

        $component->set('name', '')->call('save')->assertHasErrors(['name' => 'required']);
        $component->set('name', 'FINANCE')->call('save')->assertHasErrors(['name' => 'unique']);
        $component->set('name', str_repeat('a', 101))->call('save')->assertHasErrors(['name' => 'max']);

        $this->assertSame(1, Department::query()->count());
    }

    public function test_a_department_in_use_cannot_be_deleted(): void
    {
        $department = $this->department();
        $employee = User::factory()->withRole(RoleName::Employee)->create();
        $employee->forceFill(['department_id' => $department->id])->save();

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openDelete', $department->id)
            ->call('delete')
            ->assertSet('notice', 'This department is still assigned to employees. Deactivate it instead.');

        $this->assertModelExists($department);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::DepartmentDeleted->value)->count());
    }

    public function test_the_service_refuses_to_delete_a_used_department(): void
    {
        $department = $this->department();
        $employee = User::factory()->withRole(RoleName::Employee)->create();
        $employee->forceFill(['department_id' => $department->id])->save();

        $this->expectException(ValidationException::class);

        try {
            app(Departments::class)->delete($this->admin, $department->id);
        } finally {
            $this->assertModelExists($department);
        }
    }

    public function test_an_inactive_department_stays_on_its_employees_but_cannot_be_given_anew(): void
    {
        $inactive = $this->department('Archive', false);
        $active = $this->department('Finance');
        $kept = User::factory()->withRole(RoleName::Employee)->create();
        $kept->forceFill(['department_id' => $inactive->id])->save();
        $other = User::factory()->withRole(RoleName::Employee)->create();

        // Saving other fields keeps the inactive department.
        app(UpdateEmployment::class)->handle($kept, ['department_id' => (string) $inactive->id, 'join_date' => '2024-01-15'], $this->admin);
        $this->assertSame($inactive->id, $kept->fresh()->department_id);
        $this->assertSame('2024-01-15', $kept->fresh()->join_date->toDateString());

        Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $other->id])
            ->call('openEmployment')
            ->set('departmentId', (string) $inactive->id)
            ->call('saveEmployment')
            ->assertHasErrors(['departmentId']);
        $this->assertNull($other->fresh()->department_id);

        Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $other->id])
            ->call('openEmployment')
            ->set('departmentId', (string) $active->id)
            ->set('joinDate', '2023-07-01')
            ->call('saveEmployment')
            ->assertHasNoErrors();

        $this->assertSame($active->id, $other->fresh()->department_id);
        $this->assertSame(['department_id', 'join_date'], AuditLog::query()->where('event', AuditEvent::EmploymentUpdated->value)->latest('id')->first()->metadata['fields']);
    }

    public function test_employment_input_is_validated(): void
    {
        $employee = User::factory()->withRole(RoleName::Employee)->create();
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $employee->id])->call('openEmployment');

        $component->set('joinDate', '15-01-2024')->call('saveEmployment')->assertHasErrors(['joinDate']);
        $component->set('joinDate', '1949-12-31')->call('saveEmployment')->assertHasErrors(['joinDate']);
        $component->set('joinDate', today()->addYear()->addDay()->toDateString())->call('saveEmployment')->assertHasErrors(['joinDate']);
        $component->set('joinDate', '')->set('departmentId', '999999')->call('saveEmployment')->assertHasErrors(['departmentId']);
        $component->set('departmentId', 'x')->call('saveEmployment')->assertHasErrors(['departmentId']);

        $this->assertNull($employee->fresh()->join_date);
    }

    public function test_the_route_carries_the_permission_middleware(): void
    {
        $this->assertContains('can:'.PermissionName::DepartmentsManage->value, Route::getRoutes()->getByName('departments.index')->gatherMiddleware());
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_other_roles_are_refused_by_url_component_and_service(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();
        $department = $this->department();

        $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
        Livewire::actingAs($user)->test(Index::class)->assertForbidden();

        foreach ([
            fn () => app(Departments::class)->create($user, 'Ops'),
            fn () => app(Departments::class)->update($user, $department->id, 'Ops', true),
            fn () => app(Departments::class)->delete($user, $department->id),
        ] as $call) {
            try {
                $call();
                $this->fail('The service accepted an actor without departments.manage.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame('Finance', $department->fresh()->name);
    }

    public function test_viewing_users_alone_cannot_edit_employment(): void
    {
        $viewer = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
        $viewer->givePermissionTo(PermissionName::UsersView);
        $target = User::factory()->withRole(RoleName::Employee)->create();

        Livewire::actingAs($viewer)->test(Show::class, ['user' => (string) $target->id])
            ->assertDontSeeHtml('wire:click="openEmployment"')
            ->call('openEmployment')
            ->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(UpdateEmployment::class)->handle($target, ['department_id' => null, 'join_date' => '2024-01-01'], $viewer);
    }

    public function test_forged_property_types_are_refused(): void
    {
        $department = $this->department();

        Livewire::actingAs($this->admin)->test(Index::class)->set('name', ['x'])->assertStatus(422);
        Livewire::actingAs($this->admin)->test(Index::class)->set('active', 'yes')->assertStatus(422);
        Livewire::actingAs($this->admin)->test(Index::class)->call('openEdit', 'abc')->assertNotFound();

        $this->assertSame('Finance', $department->fresh()->name);
    }

    public function test_the_page_and_the_user_list_show_departments(): void
    {
        $department = $this->department('Logistics');
        $employee = User::factory()->withRole(RoleName::Employee)->create(['name' => 'Budi Santoso']);
        $employee->forceFill(['department_id' => $department->id])->save();

        $this->actingAs($this->admin)->get(route('departments.index'))->assertOk()->assertSee('Logistics');
        $this->get(route('users.index'))->assertOk()->assertSeeInOrder(['Department', 'Budi Santoso', 'Logistics']);
        $this->get(route('dashboard'))->assertSee(route('departments.index'));
    }
}
