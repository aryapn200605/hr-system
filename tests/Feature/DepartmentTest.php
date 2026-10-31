<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Livewire\Departments\Index;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsIn;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use LogsIn, RefreshDatabase, ReplaysLivewire;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
    }

    private function page(): mixed
    {
        return Livewire::actingAs($this->admin)->test(Index::class);
    }

    private function department(string $name = 'Finance'): Department
    {
        return Department::create(['name' => $name]);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    public function test_an_hr_admin_can_add_a_department(): void
    {
        $this->page()->call('openCreate')->set('name', '  Finance  ')->call('save')->assertHasNoErrors();

        $department = Department::sole();

        $this->assertSame('Finance', $department->name);
        $this->assertTrue($department->is_active);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::DepartmentCreated->value)->where('actor_id', $this->admin->id)->count());
    }

    public function test_an_hr_admin_can_rename_a_department(): void
    {
        $department = $this->department();

        $this->page()->call('openEdit', $department->id)->set('name', 'Accounting')->call('save')->assertHasNoErrors();

        $this->assertSame('Accounting', $department->fresh()->name);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::DepartmentUpdated->value)->count());
    }

    public function test_saving_without_a_change_records_nothing(): void
    {
        $department = $this->department();

        $this->page()->call('openEdit', $department->id)->call('save')->assertHasNoErrors();

        $this->assertSame(0, AuditLog::where('event', AuditEvent::DepartmentUpdated->value)->count());
    }

    public function test_a_name_is_required_and_has_a_limit(): void
    {
        $this->page()->call('openCreate')->set('name', '   ')->call('save')->assertHasErrors(['name' => 'required']);
        $this->page()->call('openCreate')->set('name', str_repeat('a', 101))->call('save')->assertHasErrors(['name' => 'max']);

        $this->assertSame(0, Department::count());
    }

    public function test_a_name_must_be_unique_ignoring_case(): void
    {
        $this->department();

        $this->page()->call('openCreate')->set('name', 'FINANCE')->call('save')->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, Department::count());
    }

    public function test_a_department_can_be_deactivated_and_activated_again(): void
    {
        $department = $this->department();

        $this->page()->call('toggleActive', $department->id);
        $this->assertFalse($department->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::DepartmentDeactivated->value)->count());

        $this->page()->call('toggleActive', $department->id);
        $this->assertTrue($department->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::DepartmentReactivated->value)->count());
    }

    public function test_an_unused_department_can_be_deleted(): void
    {
        $department = $this->department();

        $this->page()->call('openDelete', $department->id)->call('delete');

        $this->assertNull($department->fresh());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::DepartmentDeleted->value)->count());
    }

    public function test_a_department_in_use_cannot_be_deleted(): void
    {
        $department = $this->department();

        $page = $this->page()->call('openDelete', $department->id);

        DB::table('users')->where('id', $this->admin->id)->update(['department_id' => $department->id]);

        $page->call('delete')->assertSet('notice', 'Finance is assigned to users, so it cannot be deleted. Deactivate it instead.');

        $this->assertNotNull($department->fresh());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::DepartmentDeleted->value)->count());
    }

    public function test_the_database_refuses_to_delete_a_department_in_use(): void
    {
        $department = $this->department();
        DB::table('users')->where('id', $this->admin->id)->update(['department_id' => $department->id]);

        $this->expectException(QueryException::class);

        DB::table('departments')->where('id', $department->id)->delete();
    }

    public function test_the_page_lists_departments_with_their_user_counts(): void
    {
        $this->department();

        $this->actingAs($this->admin)->get(route('departments.index'))
            ->assertOk()
            ->assertSeeInOrder(['Finance', 'Active', '0']);
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_page_is_refused_on_a_direct_url(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_replayed_snapshot_is_refused(RoleName $role): void
    {
        $department = $this->department();
        $snapshot = $this->snapshotOf($this->admin, route('departments.index'));
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user);

        foreach ([['save', []], ['toggleActive', [$department->id]], ['openDelete', [$department->id]], ['delete', []]] as [$method, $params]) {
            $this->callLivewire($snapshot, $method, $params)->assertForbidden();
        }

        $this->assertTrue($department->fresh()->is_active);
    }

    public function test_forged_input_is_refused(): void
    {
        $this->page()->call('openEdit', 'not-a-number')->assertNotFound();
        $this->page()->call('toggleActive', 999_999)->assertNotFound();
    }

    public function test_a_forged_array_for_the_name_is_refused(): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('departments.index'));

        $this->callLivewire($snapshot, null, [], ['name' => ['a']])->assertStatus(422);
    }

    public function test_the_menu_shows_departments_only_to_those_who_may_open_it(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('Departments');

        $this->actingAs(User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create())
            ->get(route('dashboard'))->assertDontSee('Departments');
    }
}
