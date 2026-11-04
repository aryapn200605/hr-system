<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\LeavePeriod;
use App\Models\User;
use App\Services\LeaveBalance;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real races on supervisors and leave balances: separate PHP processes with separate connections,
 * on committed rows. Like the other concurrency tests, it cannot use RefreshDatabase and cleans up.
 */
class OrganizationConcurrencyTest extends TestCase
{
    private const ROUNDS = 3;

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
        DB::table('audit_logs')
            ->whereIn('actor_id', $this->userIds)
            ->orWhereIn('subject_id', $this->userIds)
            ->delete();
        // Links, periods and ledger rows go with the users (foreign key cascade).
        DB::table('users')->whereIn('id', $this->userIds)->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();

        parent::tearDown();
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->twoFactorEnrolled()->withRole($role)->create($attributes);

        $this->userIds[] = $user->id;

        return $user;
    }

    private function link(User $supervisor, User $subordinate): void
    {
        DB::table('supervisor_user')->insert(['supervisor_id' => $supervisor->id, 'subordinate_id' => $subordinate->id, 'created_at' => now()]);
    }

    private function activeSupervisorCount(User $user): int
    {
        return DB::table('supervisor_user as su')
            ->join('users as s', 's.id', '=', 'su.supervisor_id')
            ->where('su.subordinate_id', $user->id)
            ->where('s.status', UserStatus::Active->value)
            ->count();
    }

    /**
     * Run one process per move at the same moment and return the outcomes.
     *
     * @param  list<array{0: string, 1: int, 2: string}>  $moves  mode, subject ID, code
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

    public function test_two_supervisors_suspended_at_once_leave_one_active(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            [$first, $second] = [$this->user(RoleName::Approver), $this->user(RoleName::Approver)];
            $employee = $this->user(RoleName::Employee);
            $this->link($first, $employee);
            $this->link($second, $employee);

            $outcomes = $this->race([
                ['suspend', $first->id, (string) $admin->id],
                ['suspend', $second->id, (string) $admin->id],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, $this->activeSupervisorCount($employee), "Round $round");
        }
    }

    public function test_removing_one_supervisor_while_suspending_the_other_keeps_one(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            [$first, $second] = [$this->user(RoleName::Approver), $this->user(RoleName::Approver)];
            $employee = $this->user(RoleName::Employee);
            $this->link($first, $employee);
            $this->link($second, $employee);

            $outcomes = $this->race([
                ['detach', $employee->id, $admin->id.':'.$first->id],
                ['suspend', $second->id, (string) $admin->id],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, $this->activeSupervisorCount($employee), "Round $round");
        }
    }

    public function test_two_opposite_links_at_once_never_make_a_loop(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            [$a, $b] = [$this->user(RoleName::Approver), $this->user(RoleName::Approver)];

            $outcomes = $this->race([
                ['attach', $b->id, $admin->id.':'.$a->id],
                ['attach', $a->id, $admin->id.':'.$b->id],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, DB::table('supervisor_user')->whereIn('supervisor_id', [$a->id, $b->id])->count(), "Round $round");
        }
    }

    public function test_two_bulk_moves_from_the_same_supervisor_move_everyone_once(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            [$from, $toB, $toC] = [$this->user(RoleName::Approver), $this->user(RoleName::Approver), $this->user(RoleName::Approver)];
            $subordinates = collect(range(1, 4))->map(fn () => $this->user(RoleName::Employee));
            $subordinates->each(fn (User $s) => $this->link($from, $s));

            $outcomes = $this->race([
                ['move', $from->id, $admin->id.':'.$toB->id],
                ['move', $from->id, $admin->id.':'.$toC->id],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, collect($outcomes)->where('invalid', true)->count(), "Round $round: the loser finds nobody left to move.");
            $this->assertSame(0, DB::table('supervisor_user')->where('supervisor_id', $from->id)->count());

            foreach ($subordinates as $subordinate) {
                $this->assertSame(1, $this->activeSupervisorCount($subordinate), "Round $round");
            }
        }
    }

    public function test_crossing_bulk_moves_never_leave_anyone_without_a_supervisor(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            [$a, $b] = [$this->user(RoleName::Approver), $this->user(RoleName::Approver)];
            $underA = $this->user(RoleName::Employee);
            $underB = $this->user(RoleName::Employee);
            $this->link($a, $underA);
            $this->link($b, $underB);

            $outcomes = $this->race([
                ['move', $a->id, $admin->id.':'.$b->id],
                ['move', $b->id, $admin->id.':'.$a->id],
            ]);

            $this->assertGreaterThanOrEqual(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, $this->activeSupervisorCount($underA), "Round $round");
            $this->assertSame(1, $this->activeSupervisorCount($underB), "Round $round");
        }
    }

    public function test_two_resets_on_the_same_day_open_one_period(): void
    {
        $admin = $this->user(RoleName::HrAdmin);

        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->user(RoleName::Employee, ['leave_anchor_date' => today()->subYears(2)->subMonths(3)->toDateString()]);

            $outcomes = $this->race([
                ['reset', $employee->id, $admin->id.':'],
                ['reset', $employee->id, $admin->id.':'],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, collect($outcomes)->where('invalid', true)->count(), "Round $round");
            $this->assertSame(2, LeavePeriod::query()->where('user_id', $employee->id)->count(), "Round $round");
            $this->assertSame(1, LeavePeriod::query()->where('user_id', $employee->id)->whereDate('starts_on', today())->count());
        }
    }

    public function test_concurrent_adjustments_never_overspend_and_never_lose_an_update(): void
    {
        $admin = $this->user(RoleName::HrAdmin);
        $balance = app(LeaveBalance::class);

        foreach (range(1, self::ROUNDS) as $round) {
            $employee = $this->user(RoleName::Employee);

            // Two removals of 8 from 12: only one fits.
            $outcomes = $this->race([
                ['adjust', $employee->id, $admin->id.':-8'],
                ['adjust', $employee->id, $admin->id.':-8'],
            ]);
            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(4, $balance->sync($employee)->remaining(), "Round $round");

            // Four additions at once: every one counts.
            $outcomes = $this->race(array_fill(0, 4, ['adjust', $employee->id, $admin->id.':1']));
            $this->assertSame(4, $this->wins($outcomes), "Round $round");
            $this->assertSame(8, $balance->sync($employee)->remaining(), "Round $round");
            $this->assertSame(1 + 1 + 4, DB::table('leave_balance_entries')->where('user_id', $employee->id)->count());
        }
    }

    public function test_concurrent_daily_syncs_open_each_period_once(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            // Its period is deleted, so each process finds the same work to do: open the current period.
            $employee = $this->user(RoleName::Employee, ['leave_anchor_date' => today()->subYears(3)->toDateString()]);
            DB::table('leave_periods')->where('user_id', $employee->id)->delete();

            $outcomes = $this->race(array_fill(0, 3, ['sync-leave', $employee->id, '']));

            $this->assertSame(3, $this->wins($outcomes), "Round $round");
            $this->assertSame(1, LeavePeriod::query()->where('user_id', $employee->id)->count(), "Round $round");
            $this->assertSame(1, DB::table('leave_balance_entries')->where('user_id', $employee->id)->count(), "Round $round");
        }
    }
}
