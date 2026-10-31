<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Exceptions\SupervisorGuardException;
use App\Livewire\Supervisors\Move;
use App\Livewire\Users\Employment;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SupervisorRelations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\LogsIn;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class SupervisorGuardTest extends TestCase
{
    use LogsIn, RefreshDatabase, ReplaysLivewire;

    private User $admin;

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->admin = $this->userWithRole(RoleName::HrAdmin);
        $this->supervisor = $this->userWithRole(RoleName::Approver, ['name' => 'Sinta Supervisor']);
        $this->employee = $this->userWithRole(RoleName::Employee, ['name' => 'Eka Employee']);

        SupervisorRelations::assign($this->employee, $this->supervisor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(RoleName $role, array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole($role)->create($attributes);
    }

    private function usersPage(): mixed
    {
        return Livewire::actingAs($this->admin)->test(Index::class);
    }

    private function employmentPage(?User $user = null): mixed
    {
        return Livewire::actingAs($this->admin)->test(Employment::class, ['user' => (string) ($user ?? $this->employee)->id]);
    }

    private function refusals(): int
    {
        return AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->count();
    }

    private function assertStillSupervised(): void
    {
        $this->assertTrue($this->supervisor->fresh()->status === UserStatus::Active);
        $this->assertSame(RoleName::Approver->value, $this->supervisor->fresh()->roleName());
        $this->assertTrue($this->employee->supervisors()->whereKey($this->supervisor->id)->exists());
    }

    public function test_suspending_the_only_supervisor_from_the_page_is_refused_and_names_who_is_affected(): void
    {
        $page = $this->usersPage()->call('openSuspend', $this->supervisor->id)->call('confirm');

        $notice = $page->get('notice');

        $this->assertStringContainsString('Cannot suspend Sinta Supervisor', $notice);
        $this->assertStringContainsString('Eka Employee', $notice);
        $this->assertStringContainsString('Move subordinates', $notice);
        $this->assertStillSupervised();
    }

    public function test_a_refusal_is_audited_once_with_its_actor_even_though_the_transaction_rolled_back(): void
    {
        $this->usersPage()->call('openSuspend', $this->supervisor->id)->call('confirm');

        $entry = AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->sole();

        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame($this->supervisor->id, $entry->subject_id);
        $this->assertSame('would_strand', $entry->metadata['reason']);
        $this->assertSame('suspend', $entry->metadata['action']);
        $this->assertSame([$this->employee->id], $entry->metadata['employee_ids']);
    }

    public function test_changing_the_role_of_the_only_supervisor_from_the_page_is_refused(): void
    {
        $page = $this->usersPage()->call('openRole', $this->supervisor->id)
            ->set('role', RoleName::Employee->value)
            ->call('saveRole');

        $this->assertStringContainsString('Eka Employee', $page->get('notice'));
        $this->assertStillSupervised();
        $this->assertSame(1, $this->refusals());
    }

    public function test_promoting_the_only_supervisor_to_hr_admin_is_refused_too(): void
    {
        $this->expectException(SupervisorGuardException::class);

        $this->supervisor->changeRole(RoleName::HrAdmin, $this->admin);
    }

    public function test_a_supervisor_can_keep_the_approver_role(): void
    {
        $this->supervisor->changeRole(RoleName::Approver, $this->admin);

        $this->assertStillSupervised();
        $this->assertSame(0, $this->refusals());
    }

    public function test_removing_the_only_supervisor_from_the_page_is_refused(): void
    {
        $page = $this->employmentPage()->call('removeSupervisor', $this->supervisor->id);

        $this->assertStringContainsString('Cannot remove Sinta Supervisor', $page->get('notice'));
        $this->assertStillSupervised();
        $this->assertSame(1, $this->refusals());
    }

    public function test_a_direct_status_write_on_the_model_is_refused(): void
    {
        $this->supervisor->status = UserStatus::Suspended;

        try {
            $this->supervisor->save();
            $this->fail('The save went through.');
        } catch (SupervisorGuardException) {
            $this->assertSame(UserStatus::Active, $this->supervisor->fresh()->status);
            $this->assertSame(1, $this->refusals());
        }
    }

    public function test_deleting_the_only_supervisor_is_refused(): void
    {
        try {
            $this->supervisor->delete();
            $this->fail('The delete went through.');
        } catch (SupervisorGuardException) {
            $this->assertNotNull($this->supervisor->fresh());
        }
    }

    public function test_a_console_command_is_refused_and_audited_without_an_actor(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new class extends Command
        {
            protected $signature = 'test:suspend {id}';

            public function handle(): int
            {
                try {
                    User::findOrFail($this->argument('id'))->suspend();
                } catch (AccountProtectionException) {
                    return self::FAILURE;
                }

                return self::SUCCESS;
            }
        });

        $this->assertSame(1, Artisan::call('test:suspend', ['id' => $this->supervisor->id]));

        $this->assertStillSupervised();
        $this->assertNull(AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->sole()->actor_id);
    }

    public function test_a_bulk_move_to_someone_who_cannot_supervise_is_refused(): void
    {
        $target = $this->userWithRole(RoleName::Employee);

        $page = Livewire::actingAs($this->admin)->test(Move::class)
            ->set('fromId', (string) $this->supervisor->id)
            ->set('toId', (string) $target->id)
            ->call('move');

        $this->assertStringContainsString('must be an active Approver', $page->get('notice'));
        $this->assertStillSupervised();
        $this->assertSame(1, $this->refusals());
    }

    public function test_every_stranded_employee_is_named_in_one_refusal(): void
    {
        $second = $this->userWithRole(RoleName::Employee, ['name' => 'Adi Employee']);
        SupervisorRelations::assign($second, $this->supervisor);

        try {
            $this->supervisor->suspend($this->admin);
            $this->fail('The suspension went through.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('Adi Employee, Eka Employee', $exception->getMessage());
        }
    }

    public function test_a_long_list_of_names_is_cut(): void
    {
        foreach (range(1, 11) as $number) {
            SupervisorRelations::assign($this->userWithRole(RoleName::Employee), $this->supervisor);
        }

        try {
            $this->supervisor->suspend($this->admin);
            $this->fail('The suspension went through.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('and 2 more', $exception->getMessage());
        }
    }

    public function test_a_supervisor_with_a_stand_in_can_go(): void
    {
        SupervisorRelations::assign($this->employee, $this->userWithRole(RoleName::Approver));

        $this->supervisor->suspend($this->admin);

        $this->assertSame(UserStatus::Suspended, $this->supervisor->fresh()->status);
        $this->assertSame(0, $this->refusals());
    }

    public function test_a_stand_in_who_is_not_active_does_not_count(): void
    {
        $standIn = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($this->employee, $standIn);
        DB::table('users')->where('id', $standIn->id)->update(['status' => UserStatus::Suspended->value]);

        $this->expectException(SupervisorGuardException::class);

        $this->supervisor->suspend($this->admin);
    }

    public function test_a_stand_in_who_lost_the_role_does_not_count(): void
    {
        $standIn = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($this->employee, $standIn);
        DB::table('model_has_roles')->where('model_id', $standIn->id)->update([
            'role_id' => DB::table('roles')->where('name', RoleName::Employee->value)->value('id'),
        ]);

        $this->expectException(SupervisorGuardException::class);

        $this->supervisor->suspend($this->admin);
    }

    public function test_a_suspended_employee_does_not_hold_their_supervisor_back(): void
    {
        $this->employee->suspend($this->admin);

        $this->supervisor->suspend($this->admin);

        $this->assertSame(UserStatus::Suspended, $this->supervisor->fresh()->status);
    }

    public function test_a_pending_employee_counts(): void
    {
        $pending = User::factory()->pending()->withRole(RoleName::Employee)->create(['name' => 'Putu Pending']);
        SupervisorRelations::assign($pending, $this->supervisor);
        $this->employee->suspend($this->admin);

        try {
            $this->supervisor->suspend($this->admin);
            $this->fail('The suspension went through.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('Putu Pending', $exception->getMessage());
            $this->assertStringNotContainsString('Eka Employee', $exception->getMessage());
        }
    }

    public function test_a_supervisor_already_inactive_leaves_nothing_to_lose(): void
    {
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Suspended->value]);

        SupervisorRelations::remove($this->employee, $this->supervisor->fresh(), $this->admin);

        $this->assertFalse($this->employee->supervisors()->exists());
        $this->assertSame(0, $this->refusals());
    }

    public function test_a_suspended_supervisor_comes_back_with_their_relations(): void
    {
        SupervisorRelations::assign($this->employee, $this->userWithRole(RoleName::Approver));

        $this->supervisor->suspend($this->admin);
        $this->assertSame(1, $this->employee->supervisors()->activeSupervisor()->count());

        $this->supervisor->fresh()->reactivate($this->admin);
        $this->assertSame(2, $this->employee->supervisors()->activeSupervisor()->count());
    }

    public function test_someone_who_supervises_nobody_is_not_held_back(): void
    {
        $this->userWithRole(RoleName::Approver)->suspend($this->admin);

        $this->assertSame(0, $this->refusals());
    }

    public function test_an_hr_admin_can_have_more_than_one_supervisor(): void
    {
        SupervisorRelations::assign($this->admin, $this->supervisor, $this->admin);
        SupervisorRelations::assign($this->admin, $this->userWithRole(RoleName::Approver), $this->admin);

        $this->assertSame(2, $this->admin->supervisors()->activeSupervisor()->count());
    }

    public function test_an_employee_can_get_a_supervisor_from_the_page(): void
    {
        $other = $this->userWithRole(RoleName::Approver);

        $this->employmentPage()->set('supervisorId', (string) $other->id)->call('addSupervisor')->assertHasNoErrors();

        $this->assertTrue($this->employee->supervisors()->whereKey($other->id)->exists());

        $entry = AuditLog::where('event', AuditEvent::SupervisorAssigned->value)->where('actor_id', $this->admin->id)->sole();

        $this->assertSame($this->employee->id, $entry->subject_id);
        $this->assertSame($other->id, $entry->metadata['supervisor_id']);
    }

    public function test_a_relation_with_a_stand_in_can_end_from_the_page(): void
    {
        SupervisorRelations::assign($this->employee, $this->userWithRole(RoleName::Approver));

        $this->employmentPage()->call('removeSupervisor', $this->supervisor->id);

        $this->assertFalse($this->employee->supervisors()->whereKey($this->supervisor->id)->exists());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::SupervisorRemoved->value)->count());
    }

    public function test_choosing_no_supervisor_is_a_validation_error(): void
    {
        $this->employmentPage()->set('supervisorId', '')->call('addSupervisor')->assertHasErrors('supervisorId');
    }

    public function test_only_an_approver_can_be_a_supervisor(): void
    {
        foreach ([RoleName::Employee, RoleName::HrAdmin] as $role) {
            $page = $this->employmentPage()->set('supervisorId', (string) $this->userWithRole($role)->id)->call('addSupervisor');

            $this->assertStringContainsString('must be an active Approver', $page->get('notice'));
        }

        $this->assertSame(1, $this->employee->supervisors()->count());
        $this->assertSame(2, $this->refusals());
    }

    public function test_only_an_active_user_can_be_a_supervisor(): void
    {
        $suspended = User::factory()->suspended()->twoFactorEnrolled()->withRole(RoleName::Approver)->create();
        $pending = User::factory()->pending()->withRole(RoleName::Approver)->create();

        foreach ([$suspended, $pending] as $user) {
            $page = $this->employmentPage()->set('supervisorId', (string) $user->id)->call('addSupervisor');

            $this->assertStringContainsString('must be an active Approver', $page->get('notice'));
        }
    }

    public function test_nobody_can_be_their_own_supervisor(): void
    {
        $page = $this->employmentPage($this->supervisor)->set('supervisorId', (string) $this->supervisor->id)->call('addSupervisor');

        $this->assertStringContainsString('their own supervisor', $page->get('notice'));
        $this->assertFalse($this->supervisor->supervisors()->exists());
    }

    public function test_the_database_refuses_a_self_relation(): void
    {
        $this->expectException(QueryException::class);

        DB::table('employee_supervisor')->insert(['employee_id' => $this->admin->id, 'supervisor_id' => $this->admin->id]);
    }

    public function test_the_database_refuses_a_duplicate_relation(): void
    {
        $this->expectException(QueryException::class);

        DB::table('employee_supervisor')->insert(['employee_id' => $this->employee->id, 'supervisor_id' => $this->supervisor->id]);
    }

    public function test_a_direct_loop_is_refused(): void
    {
        $other = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($other, $this->supervisor);

        try {
            SupervisorRelations::assign($this->supervisor, $other, $this->admin);
            $this->fail('The loop was created.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('already above', $exception->getMessage());
        }

        $this->assertFalse($this->supervisor->supervisors()->exists());
        $this->assertSame('loop', AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->sole()->metadata['reason']);
    }

    public function test_a_longer_loop_is_refused(): void
    {
        $middle = $this->userWithRole(RoleName::Approver);
        $top = $this->userWithRole(RoleName::Approver);

        SupervisorRelations::assign($this->supervisor, $middle);
        SupervisorRelations::assign($middle, $top);

        $this->expectException(SupervisorGuardException::class);

        SupervisorRelations::assign($top, $this->supervisor, $this->admin);
    }

    public function test_a_loop_through_someone_inactive_is_refused_too(): void
    {
        $other = $this->userWithRole(RoleName::Approver);
        SupervisorRelations::assign($other, $this->supervisor);
        SupervisorRelations::assign($other, $this->userWithRole(RoleName::Approver));
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Suspended->value]);
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Active->value]);

        $this->expectException(SupervisorGuardException::class);

        SupervisorRelations::assign($this->supervisor, $other, $this->admin);
    }

    public function test_a_second_link_to_the_same_supervisor_is_refused(): void
    {
        $this->expectExceptionMessage('already a supervisor');

        SupervisorRelations::assign($this->employee, $this->supervisor, $this->admin);
    }

    public function test_removing_a_relation_that_is_gone_says_so(): void
    {
        $page = $this->employmentPage($this->admin)->call('removeSupervisor', $this->supervisor->id);

        $this->assertStringContainsString('no longer a supervisor', $page->get('notice'));
    }

    public function test_the_relations_refuse_an_actor_without_the_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        SupervisorRelations::remove($this->employee, $this->supervisor, $this->employee);
    }

    public function test_replayed_requests_change_nothing(): void
    {
        $snapshot = $this->snapshotOf($this->admin, route('users.employment', $this->employee->id));

        foreach ([RoleName::Approver, RoleName::Employee] as $role) {
            $this->actingAs($this->userWithRole($role));

            $this->callLivewire($snapshot, 'removeSupervisor', [$this->supervisor->id])->assertForbidden();
            $this->callLivewire($snapshot, 'addSupervisor')->assertForbidden();
            $this->callLivewire($snapshot, 'saveDetails')->assertForbidden();
        }

        $this->assertStillSupervised();
    }

    public function test_forged_ids_are_refused_on_the_page(): void
    {
        $this->employmentPage()->call('removeSupervisor', 'abc')->assertNotFound();
        $this->employmentPage()->call('removeSupervisor', 999_999)->assertNotFound();
    }

    public function test_the_list_marks_users_without_a_supervisor_and_filters_them(): void
    {
        $this->userWithRole(RoleName::Employee, ['name' => 'Nina Nobody']);
        $this->userWithRole(RoleName::Approver, ['name' => 'Budi Approver']);

        $this->actingAs($this->admin)->get(route('users.index'))->assertSee('No supervisor');

        $this->usersPage()->set('supervisorFilter', 'missing')
            ->assertSee('Nina Nobody')
            ->assertSee($this->admin->name)
            ->assertDontSee('Eka Employee')
            ->assertDontSee('Budi Approver');
    }

    public function test_an_employee_whose_only_supervisor_is_inactive_counts_as_missing_one(): void
    {
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Suspended->value]);

        $this->usersPage()->set('supervisorFilter', 'missing')->assertSee('Eka Employee');
    }

    public function test_an_unknown_supervisor_filter_is_ignored(): void
    {
        $this->usersPage()->set('supervisorFilter', 'nonsense')->assertSet('supervisorFilter', '')->assertSee('Eka Employee');
    }
}
