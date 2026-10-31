<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveBalancePeriod;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\SupervisorRelations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * Like UserManagementConcurrencyTest, this class cannot use RefreshDatabase and cleans up after itself.
 */
class LeaveAndSupervisorConcurrencyTest extends TestCase
{
    private const ROUNDS = 3;

    private const COMPETITORS = 6;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $periods = DB::table('leave_balance_periods')->whereIn('user_id', $this->userIds)->pluck('id');

        DB::table('leave_balance_entries')->whereIn('period_id', $periods)->delete();
        DB::table('leave_balance_periods')->whereIn('id', $periods)->delete();
        DB::table('employee_supervisor')->whereIn('employee_id', $this->userIds)->delete();
        DB::table('audit_logs')
            ->whereIn('actor_id', $this->userIds)
            ->orWhereIn('subject_id', $this->userIds)
            ->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();

        parent::tearDown();
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create();

        $this->userIds[] = $user->id;

        return $user;
    }

    /**
     * Run one process per move at the same moment and return how many won.
     *
     * @param  list<array{0: string, 1: int, 2: int|string}>  $moves  mode, subject ID, other argument
     */
    private function race(array $moves): int
    {
        $startAt = microtime(true) + 3;

        $pool = Process::pool(function ($pool) use ($moves, $startAt) {
            foreach ($moves as [$mode, $subject, $other]) {
                $pool->path(base_path())->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'hr_system_test',
                ])->command([PHP_BINARY, 'tests/Support/race.php', $mode, (string) $subject, (string) $other, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true);
        });

        $this->assertCount(count($moves), $outcomes);
        $this->assertTrue(
            $outcomes->every(fn (array $outcome) => $outcome['reached_barrier']),
            'A competitor started late, so this run proved nothing.',
        );

        return $outcomes->where('won', true)->count();
    }

    /**
     * A user whose only period ended yesterday, so a roll over is due.
     */
    private function userWithEndedPeriod(): User
    {
        $user = $this->user(RoleName::Employee);

        $period = LeaveBalances::openFirstPeriod($user);
        $period->forceFill(['start_date' => today()->subYear(), 'end_date' => today()->subDay()])->save();
        DB::table('users')->where('id', $user->id)->update(['leave_anchor_date' => today()->subYear()]);

        return $user->fresh();
    }

    public function test_suspending_both_supervisors_of_one_employee_leaves_one_active(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $secondAdmin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->user(RoleName::Employee);
            $first = $this->user(RoleName::Approver);
            $second = $this->user(RoleName::Approver);

            SupervisorRelations::assign($employee, $first);
            SupervisorRelations::assign($employee, $second);

            $winners = $this->race([
                ['suspend', $first->id, $admin->id],
                ['suspend', $second->id, $secondAdmin->id],
            ]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(1, $employee->supervisors()->activeSupervisor()->count(), 'Round '.$round);
            $this->assertSame(
                1,
                AuditLog::where('event', AuditEvent::SupervisorGuardRefused->value)->whereIn('subject_id', [$first->id, $second->id])->count(),
                'Round '.$round,
            );
        }
    }

    public function test_demoting_one_supervisor_while_suspending_the_other_leaves_one_active(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $employee = $this->user(RoleName::Employee);
        $first = $this->user(RoleName::Approver);
        $second = $this->user(RoleName::Approver);

        SupervisorRelations::assign($employee, $first);
        SupervisorRelations::assign($employee, $second);

        $winners = $this->race([
            ['suspend', $first->id, $admin->id],
            ['demote', $second->id, $admin->id],
        ]);

        $this->assertSame(1, $winners);
        $this->assertSame(1, $employee->supervisors()->activeSupervisor()->count());
    }

    public function test_two_moves_of_the_same_people_land_them_with_one_new_supervisor(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            $from = $this->user(RoleName::Approver);
            $firstTarget = $this->user(RoleName::Approver);
            $secondTarget = $this->user(RoleName::Approver);
            $employees = collect(range(1, 4))->map(fn () => $this->user(RoleName::Employee));

            $employees->each(fn (User $employee) => SupervisorRelations::assign($employee, $from));

            $winners = $this->race([
                ['move', $from->id, $firstTarget->id.':'.$admin->id],
                ['move', $from->id, $secondTarget->id.':'.$admin->id],
            ]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(0, $from->subordinates()->count(), 'Round '.$round);
            $this->assertSame(4, $firstTarget->subordinates()->count() + $secondTarget->subordinates()->count(), 'Round '.$round);

            foreach ($employees as $employee) {
                $this->assertSame(1, $employee->supervisors()->count(), 'Round '.$round);
            }
        }

        $this->assertSame(self::ROUNDS, AuditLog::where('event', AuditEvent::SupervisorsMoved->value)->count());
    }

    public function test_a_move_racing_the_suspension_of_its_target_never_strands_anyone(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $from = $this->user(RoleName::Approver);
        $to = $this->user(RoleName::Approver);
        $employee = $this->user(RoleName::Employee);

        SupervisorRelations::assign($employee, $from);

        $this->race([
            ['move', $from->id, $to->id.':'.$admin->id],
            ['suspend', $to->id, $admin->id],
        ]);

        $this->assertSame(1, $employee->supervisors()->activeSupervisor()->count());
    }

    public function test_many_daily_checks_at_once_open_one_period(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->userWithEndedPeriod();

            $winners = $this->race(array_fill(0, self::COMPETITORS, ['rollover', $user->id, 0]));

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(2, $user->leavePeriods()->count(), 'Round '.$round);
            $this->assertSame(1, $user->leavePeriods()->whereNull('closed_at')->count(), 'Round '.$round);
            $this->assertSame(1, AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->where('subject_id', $user->id)->count(), 'Round '.$round);
        }
    }

    public function test_two_manual_resets_on_one_day_open_one_period(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->user(RoleName::Employee);

            $period = LeaveBalances::openFirstPeriod($user);
            $period->forceFill(['start_date' => today()->subMonth()])->save();

            $winners = $this->race(array_fill(0, self::COMPETITORS, ['reset', $user->id, $admin->id]));

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(2, $user->leavePeriods()->count(), 'Round '.$round);
        }
    }

    public function test_a_manual_reset_racing_the_daily_check_opens_one_period(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $user = $this->userWithEndedPeriod();

        $this->race([
            ['reset', $user->id, $admin->id],
            ['rollover', $user->id, 0],
        ]);

        $this->assertSame(2, $user->leavePeriods()->count());
        $this->assertSame(1, $user->leavePeriods()->whereNull('closed_at')->count());
    }

    public function test_adjustments_at_once_all_count_and_the_ledger_stays_a_chain(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $user = $this->user(RoleName::Employee);

        LeaveBalances::openFirstPeriod($user);

        $winners = $this->race(array_fill(0, self::COMPETITORS, ['adjust', $user->id, $admin->id]));

        $period = LeaveBalancePeriod::where('user_id', $user->id)->sole();

        $this->assertSame(self::COMPETITORS, $winners);
        $this->assertSame(LeaveBalances::ANNUAL_ENTITLEMENT + self::COMPETITORS, $period->entitlement);

        $entries = LeaveBalanceEntry::where('period_id', $period->id)->where('type', 'adjusted')->orderBy('id')->get();

        $this->assertCount(self::COMPETITORS, $entries);

        foreach ($entries as $index => $entry) {
            $this->assertSame(LeaveBalances::ANNUAL_ENTITLEMENT + $index, $entry->entitlement_before);
            $this->assertSame(LeaveBalances::ANNUAL_ENTITLEMENT + $index + 1, $entry->entitlement_after);
        }
    }

    public function test_adjustments_at_once_cannot_push_used_past_the_entitlement(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $user = $this->user(RoleName::Employee);

        $period = LeaveBalances::openFirstPeriod($user);
        LeaveBalances::adjust($user, 0, 10, 'setup', $admin);

        $winners = $this->race(array_fill(0, self::COMPETITORS, ['use', $user->id, $admin->id]));

        $this->assertSame(2, $winners);
        $this->assertSame(12, $period->fresh()->used);
    }
}
