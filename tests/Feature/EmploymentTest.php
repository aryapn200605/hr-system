<?php

namespace Tests\Feature;

use App\Actions\UpdateEmployment;
use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Livewire\Users\Employment;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class EmploymentTest extends TestCase
{
    use RefreshDatabase, ReplaysLivewire;

    private User $admin;

    private User $employee;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
        $this->employee = User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
        $this->department = Department::create(['name' => 'Finance']);
    }

    private function page(): mixed
    {
        return Livewire::actingAs($this->admin)->test(Employment::class, ['user' => (string) $this->employee->id]);
    }

    private function deactivate(Department $department): void
    {
        $department->is_active = false;
        $department->save();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    public function test_a_department_and_join_date_can_be_set_and_are_audited(): void
    {
        $this->page()
            ->set('departmentId', (string) $this->department->id)
            ->set('joinedAt', '2024-03-15')
            ->call('saveDetails')
            ->assertHasNoErrors();

        $user = $this->employee->fresh();

        $this->assertSame($this->department->id, $user->department_id);
        $this->assertSame('2024-03-15', $user->joined_at->toDateString());

        $entry = AuditLog::where('event', AuditEvent::UserUpdated->value)->sole();

        $this->assertEqualsCanonicalizing(['department_id', 'joined_at'], $entry->metadata['fields']);
    }

    public function test_both_can_be_cleared(): void
    {
        $this->employee->forceFill(['department_id' => $this->department->id, 'joined_at' => '2024-03-15'])->save();

        $this->page()->set('departmentId', '')->set('joinedAt', '')->call('saveDetails')->assertHasNoErrors();

        $this->assertNull($this->employee->fresh()->department_id);
        $this->assertNull($this->employee->fresh()->joined_at);
    }

    public function test_saving_without_a_change_records_nothing(): void
    {
        $this->page()->call('saveDetails');

        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserUpdated->value)->count());
    }

    public function test_an_inactive_department_cannot_be_chosen(): void
    {
        $this->deactivate($this->department);

        $this->page()->set('departmentId', (string) $this->department->id)->call('saveDetails')->assertHasErrors('department_id');

        $this->assertNull($this->employee->fresh()->department_id);
    }

    public function test_a_user_can_stay_in_a_department_that_was_deactivated(): void
    {
        $this->employee->forceFill(['department_id' => $this->department->id])->save();
        $this->deactivate($this->department);

        $this->page()->assertSee('Finance (inactive)')->set('joinedAt', '2024-03-15')->call('saveDetails')->assertHasNoErrors();

        $this->assertSame($this->department->id, $this->employee->fresh()->department_id);
    }

    public function test_an_unknown_department_and_a_bad_date_are_refused(): void
    {
        $this->page()->set('departmentId', '999999')->call('saveDetails')->assertHasErrors('department_id');
        $this->page()->set('departmentId', 'abc')->call('saveDetails')->assertHasErrors('department_id');
        $this->page()->set('joinedAt', '15/03/2024')->call('saveDetails')->assertHasErrors('joined_at');
        $this->page()->set('joinedAt', '1900-01-01')->call('saveDetails')->assertHasErrors('joined_at');
    }

    public function test_the_action_refuses_an_actor_without_the_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        app(UpdateEmployment::class)->handle($this->employee, ['department_id' => '', 'joined_at' => ''], $this->employee);
    }

    public function test_the_detail_page_shows_employment(): void
    {
        $this->employee->forceFill(['department_id' => $this->department->id, 'joined_at' => '2024-03-15'])->save();

        $this->actingAs($this->admin)->get(route('users.show', $this->employee->id))
            ->assertOk()
            ->assertSee('Finance')
            ->assertSee('15 Mar 2024')
            ->assertSee(route('users.employment', $this->employee->id));
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_page_is_refused_on_a_direct_url(RoleName $role): void
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->actingAs($user)->get(route('users.employment', $this->employee->id))->assertForbidden();
    }

    public function test_an_unknown_user_is_not_found_only_for_those_with_access(): void
    {
        $this->actingAs($this->admin)->get('/users/999999/employment')->assertNotFound();
        $this->actingAs($this->employee)->get('/users/999999/employment')->assertForbidden();
    }

    public function test_the_target_cannot_be_forged(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->page()->set('userId', $this->admin->id);
    }

    public function test_an_array_for_a_field_is_refused(): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('users.employment', $this->employee->id));

        $this->callLivewire($snapshot, null, [], ['joinedAt' => ['2024-01-01']])->assertStatus(422);
    }
}
