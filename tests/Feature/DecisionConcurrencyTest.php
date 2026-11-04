<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\RequestLocks;
use App\Support\SupervisorRelations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DecidesRequests;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * Like the other concurrency tests, this class cannot use RefreshDatabase and cleans up after itself.
 */
class DecisionConcurrencyTest extends TestCase
{
    use DecidesRequests;

    private const ROUNDS = 3;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->tracked(RoleName::HrAdmin);
    }

    protected function tearDown(): void
    {
        $periods = DB::table('leave_balance_periods')->whereIn('user_id', $this->userIds)->pluck('id');
        $requests = DB::table('leave_requests')->whereIn('user_id', $this->userIds)->pluck('id');

        DB::table('request_locks')->delete();
        DB::table('leave_balance_entries')->whereIn('period_id', $periods)->delete();
        DB::table('leave_request_allocations')->whereIn('leave_request_id', $requests)->delete();
        DB::table('leave_requests')->whereIn('id', $requests)->delete();
        DB::table('overtime_requests')->whereIn('user_id', $this->userIds)->delete();
        DB::table('leave_balance_periods')->whereIn('id', $periods)->delete();
        DB::table('employee_supervisor')->whereIn('employee_id', $this->userIds)->delete();
        DB::table('audit_logs')->whereIn('actor_id', $this->userIds)->orWhereIn('subject_id', $this->userIds)->delete();
        DB::table('audit_logs')->whereIn('subject_type', [(new LeaveRequest)->getMorphClass(), (new OvertimeRequest)->getMorphClass()])->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();

        parent::tearDown();
    }

    private function tracked(RoleName $role): User
    {
        $user = $this->person($role);

        $this->userIds[] = $user->id;

        return $user;
    }

    /**
     * An employee with two supervisors and an open period.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function team(): array
    {
        $first = $this->tracked(RoleName::Approver);
        $second = $this->tracked(RoleName::Approver);
        $employee = $this->tracked(RoleName::Employee);

        SupervisorRelations::assign($employee, $first);
        SupervisorRelations::assign($employee, $second);
        LeaveBalances::openFirstPeriod($employee);

        return [$employee, $first, $second];
    }

    private function monday(int $weeks): string
    {
        return today()->addWeeks($weeks)->startOfWeek()->toDateString();
    }

    private function friday(int $weeks): string
    {
        return today()->addWeeks($weeks)->startOfWeek()->addDays(4)->toDateString();
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

    public function test_two_supervisors_approving_at_once_deduct_once_and_neither_errors(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first, $second] = $this->team();

            $request = $this->leave($employee, $this->monday(2), $this->friday(2));

            $winners = $this->race([['approve-leave', $first->id, $request->id], ['approve-leave', $second->id, $request->id]]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(5, $employee->leavePeriods()->sole()->used, 'Round '.$round);
            $this->assertSame(RequestStatus::Approved, $request->fresh()->status, 'Round '.$round);
            $this->assertSame(1, AuditLog::where('event', AuditEvent::RequestApproved->value)->where('subject_id', $request->id)->count(), 'Round '.$round);
        }
    }

    public function test_approve_against_reject_leaves_one_decision(): void
    {
        [$employee, $first, $second] = $this->team();

        $request = $this->leave($employee, $this->monday(2));

        $this->assertSame(1, $this->race([['approve-leave', $first->id, $request->id], ['reject-leave', $second->id, $request->id]]));

        $fresh = $request->fresh();

        $this->assertSame($fresh->status === RequestStatus::Approved ? 1 : 0, $employee->leavePeriods()->sole()->used);
    }

    public function test_approve_against_cancel_lets_one_through_and_the_balance_agrees(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first] = $this->team();

            $request = $this->leave($employee, $this->monday(2), $this->friday(2));

            $winners = $this->race([['approve-leave', $first->id, $request->id], ['cancel-leave', $employee->id, $request->id]]);

            $this->assertSame(1, $winners, 'Round '.$round);

            $status = $request->fresh()->status;

            $this->assertContains($status, [RequestStatus::Approved, RequestStatus::Cancelled], 'Round '.$round);
            $this->assertSame($status === RequestStatus::Approved ? 5 : 0, $employee->leavePeriods()->sole()->used, 'Round '.$round);
        }
    }

    public function test_two_requests_racing_for_the_last_days_let_one_through(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first] = $this->team();

            $one = $this->leave($employee, $this->monday(2), $this->friday(2));
            $two = $this->leave($employee, $this->monday(3), $this->friday(3));

            LeaveBalances::adjust($employee, 0, 5, 'race setup', $this->admin);

            $winners = $this->race([['approve-leave', $first->id, $one->id], ['approve-leave', $first->id, $two->id]]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(10, $employee->leavePeriods()->sole()->used, 'Round '.$round);
            $this->assertSame(1, AuditLog::where('event', AuditEvent::RequestApprovalRefused->value)->whereIn('subject_id', [$one->id, $two->id])->count(), 'Round '.$round);
        }
    }

    public function test_an_approval_against_an_hr_adjustment_cannot_pass_the_entitlement(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first] = $this->team();

            $request = $this->leave($employee, $this->monday(2), $this->friday(2));

            LeaveBalances::adjust($employee, 0, 6, 'race setup', $this->admin);

            $winners = $this->race([['approve-leave', $first->id, $request->id], ['adjust-used', $employee->id, $this->admin->id]]);

            $this->assertGreaterThanOrEqual(1, $winners, 'Round '.$round);

            $period = $employee->leavePeriods()->sole();

            $this->assertLessThanOrEqual($period->entitlement, $period->used, 'Round '.$round);
            $this->assertSame(
                6 + ($request->fresh()->status === RequestStatus::Approved ? 5 : 0) + ($period->used - 6 - ($request->fresh()->status === RequestStatus::Approved ? 5 : 0)),
                $period->entries()->latest('id')->value('used_after'),
                'Round '.$round,
            );
        }
    }

    public function test_a_supervisor_suspended_while_deciding_is_in_one_order_or_the_other(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first] = $this->team();

            $request = $this->leave($employee, $this->monday(2));

            $this->race([['approve-leave', $first->id, $request->id], ['suspend', $first->id, $this->admin->id]]);

            $this->assertSame(UserStatus::Suspended, $first->fresh()->status, 'Round '.$round);

            $fresh = $request->fresh();

            if ($fresh->status === RequestStatus::Approved) {
                $approved = AuditLog::where('event', AuditEvent::RequestApproved->value)->where('subject_id', $request->id)->value('id');
                $suspended = AuditLog::where('event', AuditEvent::UserSuspended->value)->where('subject_id', $first->id)->value('id');

                $this->assertLessThan($suspended, $approved, 'Round '.$round);
            } else {
                $this->assertSame(RequestStatus::Pending, $fresh->status, 'Round '.$round);
                $this->assertSame(0, $employee->leavePeriods()->sole()->used, 'Round '.$round);
            }
        }
    }

    public function test_a_supervisor_demoted_while_deciding_is_in_one_order_or_the_other(): void
    {
        [$employee, $first] = $this->team();

        $request = $this->leave($employee, $this->monday(2));

        $this->race([['approve-leave', $first->id, $request->id], ['demote', $first->id, $this->admin->id]]);

        $this->assertSame(RoleName::Employee->value, $first->fresh()->roleName());

        if ($request->fresh()->status === RequestStatus::Approved) {
            $approved = AuditLog::where('event', AuditEvent::RequestApproved->value)->where('subject_id', $request->id)->value('id');
            $demoted = AuditLog::where('event', AuditEvent::UserRoleChanged->value)->where('subject_id', $first->id)->latest('id')->value('id');

            $this->assertLessThan($demoted, $approved);
        } else {
            $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        }
    }

    public function test_two_people_taking_an_expired_lock_leave_one_holder(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $first, $second] = $this->team();

            $request = $this->leave($employee, $this->monday(2));

            RequestLocks::acquire($request, $first);
            DB::table('request_locks')->update(['expires_at' => now()->subMinute()]);

            $third = $this->tracked(RoleName::Approver);
            SupervisorRelations::assign($employee, $third);

            $this->assertSame(1, $this->race([['lock-leave', $second->id, $request->id], ['lock-leave', $third->id, $request->id]]), 'Round '.$round);
            $this->assertSame(1, DB::table('request_locks')->where('request_id', $request->id)->count(), 'Round '.$round);
        }
    }

    public function test_a_daily_check_racing_an_approval_deducts_once(): void
    {
        [$employee, $first] = $this->team();

        $request = $this->leave($employee, $this->monday(2));

        $period = $employee->leavePeriods()->sole();
        $period->forceFill(['start_date' => today()->subYear(), 'end_date' => today()->subDay()])->save();
        DB::table('users')->where('id', $employee->id)->update(['leave_anchor_date' => today()->subYear()]);

        $this->race([['approve-leave', $first->id, $request->id], ['rollover', $employee->id, 0]]);

        $this->assertSame(2, $employee->leavePeriods()->count());
        $this->assertSame(1, $employee->leavePeriods()->whereNull('closed_at')->sole()->used);
    }
}
