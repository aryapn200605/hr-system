<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAllocation;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Carbon\CarbonImmutable;
use Database\Seeders\LeaveCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real races on requests: separate PHP processes with separate connections, on committed rows.
 * Like the other concurrency tests, it cannot use RefreshDatabase and cleans up after itself.
 */
class RequestConcurrencyTest extends TestCase
{
    private const ROUNDS = 3;

    private const COMPETITORS = 4;

    /**
     * @var list<int>
     */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeaveCategorySeeder::class);
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')->whereIn('actor_id', $this->userIds)->orWhereIn('subject_id', $this->userIds)->delete();
        // Requests, allocations, links, periods and ledger rows go with the users (foreign key cascade).
        DB::table('users')->whereIn('id', $this->userIds)->delete();
        DB::table('leave_categories')->delete();
        DB::table('cache')->where('key', 'like', '%requests:submit:%')->delete();
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
     * A fresh Employee with an active supervisor, so every round has its own rate limit.
     */
    private function employee(): User
    {
        $employee = $this->user(RoleName::Employee);
        DB::table('supervisor_user')->insert([
            'supervisor_id' => $this->user(RoleName::Approver)->id,
            'subordinate_id' => $employee->id,
            'created_at' => now(),
        ]);

        return $employee;
    }

    /**
     * A Monday some weeks ahead, so every working day is in the current period.
     */
    private function monday(int $weeks): CarbonImmutable
    {
        return CarbonImmutable::today()->next(CarbonImmutable::MONDAY)->addWeeks($weeks);
    }

    /**
     * Run one process per move at the same moment and return the outcomes.
     *
     * @param  list<array{0: string, 1: int, 2: string}>  $moves  mode, user ID, code
     * @return list<array{won: bool, invalid: bool}>
     */
    private function race(array $moves): array
    {
        $startAt = microtime(true) + 3;

        $pool = Process::pool(function ($pool) use ($moves, $startAt) {
            foreach ($moves as [$mode, $subject, $code]) {
                $pool->path(base_path())->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'hr_system_test',
                ])->command([PHP_BINARY, 'tests/Support/race.php', $mode, (string) $subject, $code, (string) $startAt]);
            }
        })->start()->wait();

        $outcomes = collect($pool->collect())->map(function ($result) {
            $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

            return json_decode($result->output(), true);
        });

        $this->assertCount(count($moves), $outcomes);
        $this->assertTrue(
            $outcomes->every(fn (array $outcome) => $outcome['reached_barrier']),
            'A competitor started late, so this run proved nothing.',
        );

        return $outcomes->values()->all();
    }

    private function wins(array $outcomes): int
    {
        return collect($outcomes)->where('won', true)->count();
    }

    public function test_overlapping_submissions_by_one_user_leave_one_request(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->employee();
            $monday = $this->monday(1);

            // Every competitor overlaps every other on Wednesday.
            $moves = [];
            foreach (range(0, self::COMPETITORS - 1) as $i) {
                $moves[] = ['leave', $employee->id, $monday->addDays($i % 3)->toDateString().','.$monday->addDays(2 + $i % 2)->toDateString().',sick'];
            }

            $outcomes = $this->race($moves);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(self::COMPETITORS - 1, collect($outcomes)->where('invalid', true)->count(), "Round $round");
            $this->assertSame(1, LeaveRequest::query()->where('user_id', $employee->id)->count(), "Round $round");
        }
    }

    public function test_two_submissions_that_together_exceed_the_balance_leave_one(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->employee();
            // 8 working days each, in different weeks: together 16 of 12.
            $first = $this->monday(1);
            $second = $this->monday(4);

            $outcomes = $this->race([
                ['leave', $employee->id, $first->toDateString().','.$first->addDays(9)->toDateString().',annual'],
                ['leave', $employee->id, $second->toDateString().','.$second->addDays(9)->toDateString().',annual'],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $allocated = LeaveRequestAllocation::query()
                ->whereIn('leave_request_id', LeaveRequest::query()->where('user_id', $employee->id)->select('id'))
                ->sum('days');
            $this->assertSame(8, (int) $allocated, "Round $round");
        }
    }

    public function test_leave_and_overtime_on_the_same_date_leave_one(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->employee();
            $day = $this->monday(1);

            $outcomes = $this->race([
                ['leave', $employee->id, $day->toDateString().','.$day->toDateString().',other'],
                ['overtime', $employee->id, $day->toDateString().' 18:00,'.$day->toDateString().' 21:00'],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, LeaveRequest::query()->where('user_id', $employee->id)->count() + OvertimeRequest::query()->where('user_id', $employee->id)->count(), "Round $round");
        }
    }

    public function test_overlapping_overtime_submissions_leave_one(): void
    {
        $employee = $this->employee();
        $day = $this->monday(1)->toDateString();

        $outcomes = $this->race([
            ['overtime', $employee->id, "$day 17:00,$day 20:00"],
            ['overtime', $employee->id, "$day 19:00,$day 22:00"],
            ['overtime', $employee->id, "$day 18:00,$day 21:00"],
        ]);

        $this->assertSame(1, $this->wins($outcomes));
        $this->assertSame(1, OvertimeRequest::query()->where('user_id', $employee->id)->count());
    }

    public function test_a_double_cancel_cancels_once(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->employee();
            $day = $this->monday(1)->toDateString();
            $leave = app(LeaveRequests::class)->submit($employee, ['category' => 'annual', 'starts_on' => $day, 'ends_on' => $day, 'reason' => 'Concurrent request test.']);
            $overtime = app(OvertimeRequests::class)->submit($employee, ['start_at' => $this->monday(2)->toDateString().' 18:00', 'end_at' => $this->monday(2)->toDateString().' 20:00', 'reason' => 'Concurrent request test.']);

            $outcomes = $this->race([
                ['cancel-leave', $employee->id, (string) $leave->id],
                ['cancel-leave', $employee->id, (string) $leave->id],
                ['cancel-overtime', $employee->id, (string) $overtime->id],
                ['cancel-overtime', $employee->id, (string) $overtime->id],
            ]);

            $this->assertSame(2, $this->wins($outcomes), "Round $round");
            $this->assertSame(RequestStatus::Cancelled, $leave->fresh()->status);
            $this->assertSame(RequestStatus::Cancelled, $overtime->fresh()->status);
            $this->assertSame(1, DB::table('audit_logs')->where('event', AuditEvent::LeaveRequestCancelled->value)->where('subject_id', $leave->id)->count(), "Round $round");
            $this->assertSame(1, DB::table('audit_logs')->where('event', AuditEvent::OvertimeRequestCancelled->value)->where('subject_id', $overtime->id)->count(), "Round $round");
        }
    }
}
