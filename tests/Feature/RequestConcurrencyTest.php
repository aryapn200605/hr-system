<?php

namespace Tests\Feature;

use App\Actions\SubmitLeaveRequest;
use App\Actions\SubmitOvertimeRequest;
use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\LeaveBalances;
use App\Support\SupervisorRelations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\MakesRequests;
use Tests\TestCase;

/**
 * Real races: separate PHP processes with separate connections, on committed rows.
 *
 * Like the other concurrency tests, this class cannot use RefreshDatabase and cleans up after itself.
 */
class RequestConcurrencyTest extends TestCase
{
    use MakesRequests;

    private const ROUNDS = 3;

    private const COMPETITORS = 6;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);

        $this->supervisor = $this->tracked(RoleName::Approver);
    }

    protected function tearDown(): void
    {
        $periods = DB::table('leave_balance_periods')->whereIn('user_id', $this->userIds)->pluck('id');
        $requests = DB::table('leave_requests')->whereIn('user_id', $this->userIds)->pluck('id');

        DB::table('leave_request_allocations')->whereIn('leave_request_id', $requests)->delete();
        DB::table('leave_requests')->whereIn('id', $requests)->delete();
        DB::table('overtime_requests')->whereIn('user_id', $this->userIds)->delete();
        DB::table('leave_balance_entries')->whereIn('period_id', $periods)->delete();
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

    private function employee(): User
    {
        $employee = $this->tracked(RoleName::Employee);

        SupervisorRelations::assign($employee, $this->supervisor);
        LeaveBalances::openFirstPeriod($employee);

        return $employee;
    }

    /**
     * The Monday a given number of weeks from now, so every date is free and ahead of today.
     */
    private function monday(int $weeks): string
    {
        return today()->addWeeks($weeks)->startOfWeek()->toDateString();
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

    public function test_overlapping_leave_requests_at_once_let_one_through(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->employee();
            $day = $this->monday(2);

            $winners = $this->race(array_fill(0, self::COMPETITORS, ['leave', $user->id, $day.'|'.$day.'|sick']));

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(1, LeaveRequest::whereBelongsTo($user)->count(), 'Round '.$round);
        }
    }

    public function test_leave_requests_that_together_exceed_the_balance_let_one_through(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->employee();
            LeaveBalances::adjust($user, 0, 6, 'race setup', $this->tracked(RoleName::HrAdmin));

            $first = $this->monday(2);
            $second = $this->monday(3);

            // Four days each, on separate dates, with six left.
            $winners = $this->race([
                ['leave', $user->id, $first.'|'.today()->parse($first)->addDays(3)->toDateString().'|annual'],
                ['leave', $user->id, $second.'|'.today()->parse($second)->addDays(3)->toDateString().'|annual'],
            ]);

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(4, (int) LeaveRequest::whereBelongsTo($user)->sum('working_days'), 'Round '.$round);
        }
    }

    public function test_overlapping_overtime_at_once_lets_one_through(): void
    {
        $user = $this->employee();
        $day = $this->monday(2);

        $winners = $this->race(array_fill(0, self::COMPETITORS, ['overtime', $user->id, $day.'T18:00|'.$day.'T20:00']));

        $this->assertSame(1, $winners);
        $this->assertSame(1, OvertimeRequest::whereBelongsTo($user)->count());
    }

    public function test_leave_and_overtime_on_one_date_at_once_let_one_through(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->employee();
            $day = $this->monday(2);

            $winners = $this->race([
                ['leave', $user->id, $day.'|'.$day.'|sick'],
                ['overtime', $user->id, $day.'T18:00|'.$day.'T20:00'],
            ]);

            $this->assertSame(1, $winners, 'Round '.$round);
        }
    }

    public function test_a_double_cancel_cancels_once(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $user = $this->employee();
            $day = $this->monday(2);

            $request = app(SubmitLeaveRequest::class)->handle($user, $this->leaveInput($day), null);

            $winners = $this->race(array_fill(0, self::COMPETITORS, ['cancel-leave', $user->id, $request->id]));

            $this->assertSame(1, $winners, 'Round '.$round);
            $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status, 'Round '.$round);
            $this->assertSame(1, AuditLog::where('event', AuditEvent::LeaveRequestCancelled->value)->where('subject_id', $request->id)->count(), 'Round '.$round);
        }
    }

    public function test_a_double_cancel_of_overtime_cancels_once(): void
    {
        $user = $this->employee();
        $day = $this->monday(2);

        $request = app(SubmitOvertimeRequest::class)->handle($user, $this->overtimeInput($day.'T18:00', $day.'T20:00'), null);

        $this->assertSame(1, $this->race(array_fill(0, self::COMPETITORS, ['cancel-overtime', $user->id, $request->id])));
    }

    public function test_a_request_racing_the_daily_check_opens_one_period(): void
    {
        $user = $this->employee();

        $period = $user->leavePeriods()->sole();
        $period->forceFill(['start_date' => today()->subYear(), 'end_date' => today()->subDay()])->save();
        DB::table('users')->where('id', $user->id)->update(['leave_anchor_date' => today()->subYear()]);

        $day = $this->monday(2);

        $this->race([
            ['leave', $user->id, $day.'|'.$day.'|annual'],
            ['rollover', $user->id, 0],
            ['rollover', $user->id, 0],
        ]);

        $this->assertSame(2, $user->leavePeriods()->count());
        $this->assertSame(1, $user->leavePeriods()->whereNull('closed_at')->count());
    }
}
