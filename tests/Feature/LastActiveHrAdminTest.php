<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LastActiveHrAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function hrAdmin(): User
    {
        return User::factory()->withRole(RoleName::HrAdmin)->create();
    }

    private function assertStillActiveHrAdmin(User $user): void
    {
        $user = User::findOrFail($user->id);

        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->hasRole(RoleName::HrAdmin));
        $this->assertSame(1, User::query()->activeHrAdmin()->count());
    }

    /**
     * @return array<string, array{Closure(User): mixed}>
     */
    public static function removalPaths(): array
    {
        return [
            'changeRole to Employee' => [fn (User $admin) => $admin->changeRole(RoleName::Employee)],
            'changeRole to Approver' => [fn (User $admin) => $admin->changeRole(RoleName::Approver)],
            'suspend' => [fn (User $admin) => $admin->suspend()],
            'status set to pending' => [fn (User $admin) => $admin->forceFill(['status' => UserStatus::Pending])->save()],
            'status set and saved' => [fn (User $admin) => $admin->forceFill(['status' => UserStatus::Suspended])->save()],
            'saveQuietly' => [fn (User $admin) => $admin->forceFill(['status' => UserStatus::Suspended])->saveQuietly()],
            'saveOrFail' => [fn (User $admin) => $admin->forceFill(['status' => UserStatus::Suspended])->saveOrFail()],
            'delete' => [fn (User $admin) => $admin->delete()],
            'destroy' => [fn (User $admin) => User::destroy($admin->id)],
        ];
    }

    /**
     * @param  Closure(User): mixed  $path
     */
    #[DataProvider('removalPaths')]
    public function test_the_last_active_hr_admin_cannot_be_removed(Closure $path): void
    {
        $admin = $this->hrAdmin();

        try {
            $path($admin);
            $this->fail('The last active HR Admin was removed.');
        } catch (AccountProtectionException) {
            $this->assertStillActiveHrAdmin($admin);
        }
    }

    /**
     * @return array<string, array{Closure(User): mixed}>
     */
    public static function spatieRolePaths(): array
    {
        return [
            'assignRole' => [fn (User $user) => $user->assignRole(RoleName::Employee)],
            'removeRole' => [fn (User $user) => $user->removeRole(RoleName::HrAdmin)],
            'syncRoles' => [fn (User $user) => $user->syncRoles([RoleName::Employee])],
            'syncRoles to none' => [fn (User $user) => $user->syncRoles([])],
        ];
    }

    /**
     * @param  Closure(User): mixed  $path
     */
    #[DataProvider('spatieRolePaths')]
    public function test_spatie_role_methods_are_refused(Closure $path): void
    {
        $admin = $this->hrAdmin();

        try {
            $path($admin);
            $this->fail('A Spatie role method changed roles directly.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('changeRole', $exception->getMessage());
            $this->assertStillActiveHrAdmin($admin);
        }
    }

    public function test_another_hr_admin_can_be_demoted_while_one_stays_active(): void
    {
        $admin = $this->hrAdmin();
        $other = $this->hrAdmin();

        $other->changeRole(RoleName::Employee, $admin);

        $this->assertTrue($other->fresh()->hasRole(RoleName::Employee));
        $this->assertFalse($other->fresh()->hasRole(RoleName::HrAdmin));
        $this->assertSame(1, $other->fresh()->roles()->count());
    }

    public function test_an_hr_admin_can_be_suspended_while_another_stays_active(): void
    {
        $this->hrAdmin();
        $other = $this->hrAdmin();

        $other->suspend();

        $this->assertSame(UserStatus::Suspended, $other->fresh()->status);
    }

    public function test_the_second_of_two_admins_cannot_be_removed_after_the_first(): void
    {
        $first = $this->hrAdmin();
        $second = $this->hrAdmin();

        $first->suspend();

        $this->expectException(AccountProtectionException::class);

        $second->suspend();
    }

    public function test_the_check_reads_the_database_and_not_a_stale_instance(): void
    {
        $first = $this->hrAdmin();
        $second = $this->hrAdmin();
        $staleSecond = User::findOrFail($second->id);

        $first->suspend();

        $this->expectException(AccountProtectionException::class);

        $staleSecond->changeRole(RoleName::Employee);
    }

    public function test_a_failed_suspend_leaves_no_audit_entry(): void
    {
        $admin = $this->hrAdmin();

        try {
            $admin->suspend();
        } catch (AccountProtectionException) {
            //
        }

        $this->assertFalse(AuditLog::where('event', AuditEvent::UserSuspended->value)->exists());
    }

    public function test_suspended_hr_admins_do_not_count_as_active(): void
    {
        $active = $this->hrAdmin();
        User::factory()->suspended()->withRole(RoleName::HrAdmin)->create();

        $this->expectException(AccountProtectionException::class);

        $active->suspend();
    }

    public function test_a_suspended_hr_admin_can_be_demoted_when_it_does_not_affect_the_last_active_one(): void
    {
        $active = $this->hrAdmin();
        $suspended = User::factory()->suspended()->withRole(RoleName::HrAdmin)->create();

        $suspended->changeRole(RoleName::Employee, $active);

        $this->assertTrue($suspended->fresh()->hasRole(RoleName::Employee));
    }

    public function test_re_assigning_the_same_role_to_the_last_admin_is_allowed(): void
    {
        $admin = $this->hrAdmin();

        $admin->changeRole(RoleName::HrAdmin);

        $this->assertStillActiveHrAdmin($admin);
    }

    public function test_an_hr_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->hrAdmin();
        $this->hrAdmin();

        $this->expectException(AccountProtectionException::class);

        $admin->changeRole(RoleName::Employee, $admin);
    }

    public function test_users_without_permission_cannot_change_roles(): void
    {
        $employee = User::factory()->withRole(RoleName::Employee)->create();
        $target = User::factory()->withRole(RoleName::Employee)->create();

        $this->assertFalse($employee->hasPermissionTo(PermissionName::UsersUpdate));

        $this->expectException(AuthorizationException::class);

        $target->changeRole(RoleName::HrAdmin, $employee);
    }

    public function test_a_user_holds_one_role_after_a_change(): void
    {
        $user = User::factory()->withRole(RoleName::Employee)->create();

        $user->changeRole(RoleName::Approver);

        $this->assertSame([RoleName::Approver->value], $user->fresh()->getRoleNames()->all());
    }

    public function test_role_changes_are_audited(): void
    {
        $admin = $this->hrAdmin();
        $user = User::factory()->withRole(RoleName::Employee)->create();

        $user->changeRole(RoleName::Approver, $admin);

        $entry = AuditLog::where('event', AuditEvent::UserRoleChanged->value)->latest('id')->first();

        $this->assertTrue($entry->actor->is($admin));
        $this->assertTrue($entry->subject->is($user));
        $this->assertSame(['role' => RoleName::Approver->value], $entry->metadata);
    }

    public function test_role_changes_lock_the_hr_admin_role_row(): void
    {
        $this->hrAdmin();
        $admin = $this->hrAdmin();
        DB::enableQueryLog();

        $admin->changeRole(RoleName::Employee);

        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'for update'));

        $this->assertNotEmpty($locks->filter(fn (string $query) => str_contains($query, '`roles`')));
    }
}
