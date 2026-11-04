<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\SupervisorGuardException;
use App\Livewire\Users\Index;
use App\Livewire\Users\Show;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Supervisors;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->hrAdmin();
    }

    private function approver(array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::Approver)->create($attributes);
    }

    private function employee(array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create($attributes);
    }

    private function link(User $supervisor, User $subordinate): void
    {
        DB::table('supervisor_user')->insert(['supervisor_id' => $supervisor->id, 'subordinate_id' => $subordinate->id, 'created_at' => now()]);
    }

    private function linked(User $supervisor, User $subordinate): bool
    {
        return DB::table('supervisor_user')->where('supervisor_id', $supervisor->id)->where('subordinate_id', $subordinate->id)->exists();
    }

    private function attach(User $subordinate, mixed $supervisorId): void
    {
        app(Supervisors::class)->attach($this->admin, $subordinate, $supervisorId);
    }

    /**
     * @return array<string, string>
     */
    private function attachError(User $subordinate, mixed $supervisorId): string
    {
        try {
            $this->attach($subordinate, $supervisorId);
        } catch (ValidationException $exception) {
            return $exception->errors()['supervisor'][0];
        }

        $this->fail('The link was accepted.');
    }

    private function refusals(): int
    {
        return AuditLog::query()->where('event', AuditEvent::SupervisorGuardRefused->value)->count();
    }

    // --- Linking -------------------------------------------------------------------------------

    public function test_an_admin_adds_and_removes_a_supervisor_from_the_detail_page(): void
    {
        $employee = $this->employee();
        $first = $this->approver(['name' => 'Ayu Lestari']);
        $second = $this->approver();

        $page = Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $employee->id])
            ->assertSee('No supervisor')
            ->call('openAddSupervisor')
            ->set('supervisor', (string) $first->id)
            ->call('addSupervisor')
            ->assertHasNoErrors()
            ->assertSee('Ayu Lestari');

        $page->call('openAddSupervisor')->set('supervisor', (string) $second->id)->call('addSupervisor');
        $page->call('removeSupervisor', $first->id)->assertSet('notice', '');

        $this->assertFalse($this->linked($first, $employee));
        $this->assertTrue($this->linked($second, $employee));
        $this->assertSame(2, AuditLog::query()->where('event', AuditEvent::SupervisorAttached->value)->count());
        $this->assertSame(
            ['supervisor_id' => $first->id],
            AuditLog::query()->where('event', AuditEvent::SupervisorDetached->value)->sole()->metadata,
        );
    }

    public function test_only_an_active_approver_can_supervise(): void
    {
        $employee = $this->employee();

        foreach ([
            $this->employee(),
            $this->hrAdmin(),
            User::factory()->suspended()->withRole(RoleName::Approver)->create(),
            User::factory()->pending()->withRole(RoleName::Approver)->create(),
        ] as $candidate) {
            $this->assertSame('Choose an active Approver as supervisor.', $this->attachError($employee, $candidate->id));
        }

        foreach (['', 'abc', '0', '-1', ['1'], 999999] as $forged) {
            $this->assertNotSame('', $this->attachError($employee, $forged));
        }

        $this->assertSame(0, DB::table('supervisor_user')->count());
    }

    public function test_no_self_reference_no_duplicate_and_no_cycle(): void
    {
        $top = $this->approver(['name' => 'Top']);
        $middle = $this->approver(['name' => 'Middle']);
        $bottom = $this->approver(['name' => 'Bottom']);

        $this->assertSame('A user cannot supervise themselves.', $this->attachError($top, $top->id));

        $this->attach($middle, $top->id);
        $this->attach($bottom, $middle->id);

        $this->assertSame('Top is already a supervisor of this user.', $this->attachError($middle, $top->id));
        // Direct and indirect loops.
        $this->assertStringStartsWith('Middle is supervised by this user', $this->attachError($top, $middle->id));
        $this->assertStringStartsWith('Bottom is supervised by this user', $this->attachError($top, $bottom->id));

        $this->assertSame(2, DB::table('supervisor_user')->count());
    }

    public function test_the_unique_index_and_the_foreign_keys_hold_in_the_database(): void
    {
        $approver = $this->approver();
        $employee = $this->employee();
        $this->link($approver, $employee);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->link($approver, $employee);
    }

    // --- The guard, from every entry point ------------------------------------------------------

    public function test_removing_the_last_active_supervisor_is_refused_and_audited_after_the_rollback(): void
    {
        $approver = $this->approver();
        $employee = $this->employee(['name' => 'Sari Dewi']);
        $this->link($approver, $employee);

        $page = Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $employee->id])
            ->call('removeSupervisor', $approver->id);

        $page->assertSet('notice', 'This would leave Sari Dewi without an active supervisor. Give them another supervisor first, or move them to another supervisor, then try again.');
        $this->assertTrue($this->linked($approver, $employee));
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::SupervisorDetached->value)->count());

        $refusal = AuditLog::query()->where('event', AuditEvent::SupervisorGuardRefused->value)->sole();
        $this->assertSame($this->admin->id, $refusal->actor_id);
        $this->assertEquals(['action' => 'detach', 'supervisor_ids' => [$approver->id], 'affected_ids' => [$employee->id]], $refusal->metadata);
    }

    public function test_a_second_active_supervisor_allows_the_removal(): void
    {
        [$first, $second] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $this->link($first, $employee);
        $this->link($second, $employee);

        app(Supervisors::class)->detach($this->admin, $employee, $first->id);

        $this->assertFalse($this->linked($first, $employee));
        $this->assertSame(0, $this->refusals());
    }

    public function test_a_suspended_or_demoted_second_supervisor_does_not_count(): void
    {
        [$first, $suspended] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $this->link($first, $employee);
        $this->link($suspended, $employee);
        $suspended->suspend($this->admin);

        $this->expectException(SupervisorGuardException::class);

        try {
            app(Supervisors::class)->detach($this->admin, $employee, $first->id);
        } finally {
            $this->assertTrue($this->linked($first, $employee));
        }
    }

    public function test_a_suspended_subordinate_is_not_counted(): void
    {
        $approver = $this->approver();
        $suspended = User::factory()->suspended()->withRole(RoleName::Employee)->create();
        $this->link($approver, $suspended);

        $approver->suspend($this->admin);

        $this->assertSame(UserStatus::Suspended, $approver->fresh()->status);
        $this->assertTrue($this->linked($approver, $suspended), 'Suspension keeps the links.');
    }

    public function test_a_pending_subordinate_is_counted(): void
    {
        $approver = $this->approver();
        $pending = User::factory()->pending()->withRole(RoleName::Employee)->create();
        $this->link($approver, $pending);

        $this->expectException(SupervisorGuardException::class);
        $approver->suspend($this->admin);
    }

    public function test_suspending_the_only_supervisor_is_refused_from_the_user_list(): void
    {
        $approver = $this->approver();
        $employee = $this->employee(['name' => 'Joko Widodo']);
        $this->link($approver, $employee);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('openSuspend', $approver->id)
            ->call('confirm')
            ->assertSet('notice', 'This would leave Joko Widodo without an active supervisor. Give them another supervisor first, or move them to another supervisor, then try again.');

        $this->assertSame(UserStatus::Active, $approver->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::UserSuspended->value)->count());

        // One refusal row, from the outermost entry point, though save() runs the guard inside suspend().
        $refusal = AuditLog::query()->where('event', AuditEvent::SupervisorGuardRefused->value)->sole();
        $this->assertSame('suspend', $refusal->metadata['action']);
        $this->assertSame($this->admin->id, $refusal->actor_id);
    }

    public function test_a_suspension_keeps_the_links_and_reactivation_counts_them_again(): void
    {
        [$first, $second] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $this->link($first, $employee);
        $this->link($second, $employee);

        $first->suspend($this->admin);
        $this->assertTrue($this->linked($first, $employee));
        $this->assertTrue($employee->hasActiveSupervisor());

        // Now second is the only one counted.
        try {
            $second->suspend($this->admin);
            $this->fail('The last active supervisor was suspended.');
        } catch (SupervisorGuardException) {
        }

        $first->fresh()->reactivate($this->admin);
        $second->fresh()->suspend($this->admin);

        $this->assertSame(UserStatus::Suspended, $second->fresh()->status);
        $this->assertTrue($employee->hasActiveSupervisor());
    }

    public function test_losing_the_approver_role_removes_the_links_and_is_guarded(): void
    {
        [$first, $second] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $other = $this->employee();
        $this->link($first, $employee);
        $this->link($second, $employee);
        $this->link($first, $other);

        // $other has only $first.
        try {
            $first->changeRole(RoleName::Employee, $this->admin);
            $this->fail('The role change left a user without a supervisor.');
        } catch (SupervisorGuardException) {
        }

        $this->assertSame(RoleName::Approver->value, $first->fresh()->roleName());
        $this->assertSame('role_change', AuditLog::query()->where('event', AuditEvent::SupervisorGuardRefused->value)->sole()->metadata['action']);

        $this->link($second, $other);
        $first->changeRole(RoleName::Employee, $this->admin);

        $this->assertSame(0, DB::table('supervisor_user')->where('supervisor_id', $first->id)->count());
        $this->assertEquals(
            ['role' => RoleName::Employee->value, 'supervisor_links_removed' => 2],
            AuditLog::query()->where('event', AuditEvent::UserRoleChanged->value)->where('subject_id', $first->id)->latest('id')->first()->metadata,
        );

        // Becoming an Approver again does not bring the links back.
        $first->changeRole(RoleName::Approver, $this->admin);
        $this->assertSame(0, DB::table('supervisor_user')->where('supervisor_id', $first->id)->count());
    }

    public function test_a_role_change_of_a_subordinate_keeps_their_supervisors(): void
    {
        $approver = $this->approver();
        $employee = $this->employee();
        $this->link($approver, $employee);

        $employee->changeRole(RoleName::HrAdmin, $this->admin);

        $this->assertTrue($this->linked($approver, $employee));
    }

    public function test_deleting_the_only_supervisor_is_refused(): void
    {
        $approver = $this->approver();
        $employee = $this->employee();
        $this->link($approver, $employee);

        try {
            $approver->delete();
            $this->fail('The delete left a user without a supervisor.');
        } catch (SupervisorGuardException) {
        }

        $this->assertModelExists($approver);
        $this->assertSame('delete', AuditLog::query()->where('event', AuditEvent::SupervisorGuardRefused->value)->sole()->metadata['action']);

        $this->link($this->approver(), $employee);
        $approver->delete();

        $this->assertModelMissing($approver);
        $this->assertFalse($this->linked($approver, $employee));
    }

    public function test_a_status_change_through_save_is_guarded_too(): void
    {
        $approver = $this->approver();
        $employee = $this->employee();
        $this->link($approver, $employee);

        $approver->status = UserStatus::Suspended;

        $this->expectException(SupervisorGuardException::class);
        $approver->save();
    }

    public function test_a_refused_save_or_delete_leaves_the_instance_as_it_was(): void
    {
        $approver = $this->approver();
        $this->link($approver, $this->employee());

        $approver->status = UserStatus::Suspended;

        try {
            $approver->save();
            $this->fail('The save was accepted.');
        } catch (SupervisorGuardException) {
        }

        $this->assertTrue($approver->isDirty('status'), 'The instance must not believe the status was saved.');
        $this->assertSame(UserStatus::Active, $approver->getOriginal('status'));
        $this->assertSame(UserStatus::Active, $approver->fresh()->status);

        $approver->status = UserStatus::Active;

        try {
            $approver->delete();
            $this->fail('The delete was accepted.');
        } catch (SupervisorGuardException) {
        }

        $this->assertTrue($approver->exists);
    }

    public function test_the_new_texts_show_in_indonesian(): void
    {
        $this->admin->forceFill(['locale' => 'id'])->save();
        $approver = $this->approver();
        $employee = $this->employee(['name' => 'Sari Dewi']);
        $this->link($approver, $employee);

        $this->actingAs($this->admin)->get(route('users.show', $this->employee()->id))
            ->assertOk()->assertSee('Belum punya atasan')->assertSee('Data kepegawaian')->assertSee('Tambah atasan');

        $page = Livewire::actingAs($this->admin->fresh())->test(Show::class, ['user' => (string) $employee->id]);
        app()->setLocale('id');
        $page->call('removeSupervisor', $approver->id)
            ->assertSet('notice', 'Tindakan ini akan membuat Sari Dewi tidak punya atasan aktif. Berikan atasan lain terlebih dahulu, atau pindahkan mereka ke atasan lain, lalu coba lagi.');
    }

    public function test_an_approver_subordinate_is_guarded_but_never_marked(): void
    {
        $top = $this->approver();
        $approver = $this->approver(['name' => 'Wulan']);
        $this->link($top, $approver);

        $this->assertFalse($approver->lacksSupervisor());

        try {
            $top->suspend($this->admin);
            $this->fail('The guard skipped an Approver subordinate.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringContainsString('Wulan', $exception->getMessage());
        }

        // Without any supervisor an Approver has no marker, and nothing refuses them.
        $lone = $this->approver();
        $this->assertFalse($lone->lacksSupervisor());
        $lone->suspend($this->admin);
    }

    public function test_the_message_names_five_users_and_counts_the_rest(): void
    {
        $approver = $this->approver();

        foreach (range(1, 7) as $i) {
            $this->link($approver, $this->employee(['name' => "Person $i"]));
        }

        try {
            $approver->suspend($this->admin);
            $this->fail('The suspension was accepted.');
        } catch (SupervisorGuardException $exception) {
            $this->assertStringStartsWith('This would leave Person 1, Person 2, Person 3, Person 4, Person 5 and 2 more without', $exception->getMessage());
            $this->assertCount(7, $exception->affected);
        }
    }

    // --- Bulk move -------------------------------------------------------------------------------

    public function test_moving_all_subordinates_in_one_transaction(): void
    {
        [$from, $to] = [$this->approver(), $this->approver()];
        [$a, $b] = [$this->employee(), $this->employee()];
        $this->link($from, $a);
        $this->link($from, $b);
        $this->link($to, $b);

        Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $from->id])
            ->call('openMove')
            ->set('target', (string) $to->id)
            ->call('moveSubordinates')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Subordinates moved: 2.');

        $this->assertSame(0, DB::table('supervisor_user')->where('supervisor_id', $from->id)->count());
        $this->assertTrue($this->linked($to, $a));
        $this->assertSame(1, DB::table('supervisor_user')->where('supervisor_id', $to->id)->where('subordinate_id', $b->id)->count());

        $audit = AuditLog::query()->where('event', AuditEvent::SupervisorsMoved->value)->sole();
        $this->assertEquals(['from_id' => $from->id, 'to_id' => $to->id, 'subordinate_ids' => [$a->id, $b->id]], $audit->metadata);
    }

    public function test_moving_selected_subordinates(): void
    {
        [$from, $to] = [$this->approver(), $this->approver()];
        [$a, $b] = [$this->employee(), $this->employee()];
        $this->link($from, $a);
        $this->link($from, $b);

        Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $from->id])
            ->call('openMove')
            ->set('moveAll', false)
            ->set('target', (string) $to->id)
            ->set('selection', [(string) $b->id])
            ->call('moveSubordinates')
            ->assertHasNoErrors();

        $this->assertTrue($this->linked($from, $a));
        $this->assertFalse($this->linked($from, $b));
        $this->assertTrue($this->linked($to, $b));
    }

    public function test_a_move_is_validated_as_a_whole(): void
    {
        [$from, $to, $outsider] = [$this->approver(), $this->approver(), $this->approver()];
        $employee = $this->employee();
        $stranger = $this->employee();
        $this->link($from, $employee);
        $this->link($from, $to);

        $move = fn (mixed $target, ?array $ids) => app(Supervisors::class)->move($this->admin, $from, $target, $ids);
        $errors = function (callable $call) {
            try {
                $call();
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            $this->fail('The move was accepted.');
        };

        $this->assertArrayHasKey('target', $errors(fn () => $move($from->id, null)));
        $this->assertArrayHasKey('target', $errors(fn () => $move($employee->id, null)));
        $this->assertArrayHasKey('selection', $errors(fn () => $move($outsider->id, [$stranger->id])));
        $this->assertArrayHasKey('selection', $errors(fn () => $move($outsider->id, [])));
        $this->assertArrayHasKey('selection', $errors(fn () => $move($outsider->id, ['abc'])));
        // $to is one of the subordinates: it cannot become its own supervisor.
        $this->assertArrayHasKey('selection', $errors(fn () => $move($to->id, null)));

        // A loop: once $outsider is under $to, $to cannot move under $outsider.
        $this->link($to, $outsider);
        $this->assertStringContainsString('so cannot be moved under them', $errors(fn () => $move($outsider->id, [$to->id]))['selection'][0]);

        $this->assertTrue($this->linked($from, $employee));
        $this->assertTrue($this->linked($from, $to));
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::SupervisorsMoved->value)->count());
    }

    public function test_a_forged_selection_is_refused(): void
    {
        $from = $this->approver();
        $this->link($from, $this->employee());

        $page = fn () => Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $from->id]);

        $page()->set('selection', 'x')->assertStatus(422);
        $page()->set('selection', [['nested']])->assertStatus(422);
        $page()->set('selection', ['a' => '1'])->assertStatus(422);
        $page()->set('moveAll', 'no')->assertStatus(422);
        $page()->set('target', ['1'])->assertStatus(422);
    }

    // --- Marker and filter -------------------------------------------------------------------------

    public function test_the_list_marks_and_filters_users_without_a_supervisor(): void
    {
        $approver = $this->approver(['name' => 'Approver Without']);
        $lonely = $this->employee(['name' => 'Employee Without']);
        $covered = $this->employee(['name' => 'Covered Person']);
        $this->link($approver, $covered);
        $suspended = User::factory()->suspended()->withRole(RoleName::Employee)->create(['name' => 'Suspended Without']);
        $orphanAdmin = $this->hrAdmin();
        $orphanAdmin->forceFill(['name' => 'Admin Without'])->save();

        $list = Livewire::actingAs($this->admin)->test(Index::class)->set('supervisorFilter', 'none');

        $list->assertSee('Employee Without')->assertSee('Admin Without')
            ->assertDontSee('Covered Person')->assertDontSee('Approver Without')->assertDontSee('Suspended Without');

        $list->set('supervisorFilter', 'bogus')->assertSet('supervisorFilter', '')->assertSee('Approver Without');

        $html = $this->actingAs($this->admin)->get(route('users.index'))->getContent();
        // The admin, the lonely employee and the second admin are marked; the approver, the covered and the suspended user are not.
        $this->assertSame(3, substr_count($html, 'data-no-supervisor='), $html);
    }

    // --- Access -------------------------------------------------------------------------------------

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_the_service_refuses_other_roles(RoleName $role): void
    {
        $actor = User::factory()->twoFactorEnrolled()->withRole($role)->create();
        [$from, $to] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $this->link($from, $employee);

        foreach ([
            fn () => app(Supervisors::class)->attach($actor, $employee, $to->id),
            fn () => app(Supervisors::class)->detach($actor, $employee, $from->id),
            fn () => app(Supervisors::class)->move($actor, $from, $to->id, null),
        ] as $call) {
            try {
                $call();
                $this->fail('The service accepted an actor without supervisors.manage.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(1, DB::table('supervisor_user')->count());
    }

    public function test_viewing_users_alone_cannot_change_supervisors(): void
    {
        $viewer = $this->employee();
        $viewer->givePermissionTo(PermissionName::UsersView);
        [$from, $to] = [$this->approver(), $this->approver()];
        $employee = $this->employee();
        $this->link($from, $employee);

        Livewire::actingAs($viewer)->test(Show::class, ['user' => (string) $employee->id])
            ->assertDontSeeHtml('wire:click="openAddSupervisor"')
            ->call('openAddSupervisor')->assertForbidden();
        Livewire::actingAs($viewer)->test(Show::class, ['user' => (string) $employee->id])
            ->set('supervisor', (string) $to->id)->call('addSupervisor')->assertForbidden();
        Livewire::actingAs($viewer)->test(Show::class, ['user' => (string) $employee->id])
            ->call('removeSupervisor', $from->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(Show::class, ['user' => (string) $from->id])
            ->set('target', (string) $to->id)->call('moveSubordinates')->assertForbidden();

        $this->assertSame(1, DB::table('supervisor_user')->count());
        $this->assertTrue($this->linked($from, $employee));
    }
}
