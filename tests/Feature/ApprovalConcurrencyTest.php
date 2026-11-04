<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\LeaveBalance;
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
 * Real races on decisions (DECISIONS.md section 6): separate PHP processes with separate connections, on
 * committed rows. A deadlock or any other database error fails the process and therefore the test, so
 * every run here also shows that the lock order (README "Approvals") does not deadlock against cancels,
 * balance changes and account changes. Like the other concurrency tests it cannot use RefreshDatabase
 * and cleans up after itself.
 */
class ApprovalConcurrencyTest extends TestCase
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
        $this->seed(LeaveCategorySeeder::class);
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')->whereIn('actor_id', $this->userIds)->orWhereIn('subject_id', $this->userIds)->delete();
        DB::table('audit_logs')->whereIn('subject_type', [LeaveRequest::class, OvertimeRequest::class])->delete();
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
     * A fresh Employee with this many supervisors, so every round has its own rate limit.
     *
     * @return array{0: User, 1: list<User>}
     */
    private function team(int $supervisors): array
    {
        $employee = $this->user(RoleName::Employee);
        $list = [];

        foreach (range(1, $supervisors) as $i) {
            $list[] = $supervisor = $this->user(RoleName::Approver);
            DB::table('supervisor_user')->insert(['supervisor_id' => $supervisor->id, 'subordinate_id' => $employee->id, 'created_at' => now()]);
        }

        return [$employee, $list];
    }

    private function monday(int $weeks): CarbonImmutable
    {
        return CarbonImmutable::today()->next(CarbonImmutable::MONDAY)->addWeeks($weeks);
    }

    /**
     * Five working days from a Monday some weeks ahead.
     */
    private function leave(User $employee, int $weeks): LeaveRequest
    {
        $monday = $this->monday($weeks);

        return app(LeaveRequests::class)->submit($employee, [
            'category' => 'annual',
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->addDays(4)->toDateString(),
            'reason' => 'Concurrent decision test.',
        ]);
    }

    private function period(User $employee): LeavePeriod
    {
        return LeavePeriod::query()->where('user_id', $employee->id)->sole();
    }

    private function deductions(User $employee): int
    {
        return LeaveBalanceEntry::query()->where('user_id', $employee->id)->where('type', LeaveEntryType::Deducted->value)->count();
    }

    /**
     * Run one process per move at the same moment and return the outcomes.
     *
     * @param  list<array{0: string, 1: int, 2: string}>  $moves  mode, user ID, code
     * @return list<array{won: bool, invalid: bool, refused: bool}>
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

    public function test_supervisors_deciding_at_once_decide_once(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $supervisors] = $this->team(4);
            $request = $this->leave($employee, 1);

            $outcomes = $this->race([
                ['approve', $supervisors[0]->id, "leave:$request->id"],
                ['approve', $supervisors[1]->id, "leave:$request->id"],
                ['reject', $supervisors[2]->id, "leave:$request->id"],
                ['approve', $supervisors[3]->id, "leave:$request->id"],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            // The losers are told who decided, which is not an error.
            $this->assertSame(3, collect($outcomes)->where('invalid', true)->count(), "Round $round");

            $request->refresh();
            $approved = $request->status === RequestStatus::Approved;
            $this->assertContains($request->decided_by, collect($supervisors)->pluck('id')->all());
            $this->assertSame($approved ? 5 : 0, $this->period($employee)->used, "Round $round");
            $this->assertSame($approved ? 1 : 0, $this->deductions($employee), "Round $round");
            $this->assertSame(1, DB::table('audit_logs')->where('subject_id', $request->id)->whereIn('event', [AuditEvent::LeaveRequestApproved->value, AuditEvent::LeaveRequestRejected->value])->count(), "Round $round");
        }
    }

    public function test_approve_against_cancel_ends_with_one_outcome(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, [$supervisor]] = $this->team(1);
            $request = $this->leave($employee, 1);
            $overtime = app(OvertimeRequests::class)->submit($employee, [
                'start_at' => $this->monday(3)->toDateString().' 18:00',
                'end_at' => $this->monday(3)->toDateString().' 20:00',
                'reason' => 'Concurrent decision test.',
            ]);

            $outcomes = $this->race([
                ['approve', $supervisor->id, "leave:$request->id"],
                ['cancel-leave', $employee->id, (string) $request->id],
                ['reject', $supervisor->id, "overtime:$overtime->id"],
                ['cancel-overtime', $employee->id, (string) $overtime->id],
            ]);

            $this->assertSame(2, $this->wins($outcomes), "Round $round");
            $this->assertSame(2, collect($outcomes)->where('invalid', true)->count(), "Round $round");

            $request->refresh();
            if ($request->status === RequestStatus::Approved) {
                $this->assertNull($request->cancelled_at);
                $this->assertSame([5, 1], [$this->period($employee)->used, $this->deductions($employee)], "Round $round");
            } else {
                $this->assertSame(RequestStatus::Cancelled, $request->status, "Round $round");
                $this->assertNull($request->decided_by);
                $this->assertSame([0, 0], [$this->period($employee)->used, $this->deductions($employee)], "Round $round");
            }

            $this->assertContains($overtime->fresh()->status, [RequestStatus::Rejected, RequestStatus::Cancelled]);
        }
    }

    public function test_two_requests_competing_for_the_last_days_approve_one(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $supervisors] = $this->team(2);
            $first = $this->leave($employee, 1);
            $second = $this->leave($employee, 2);
            // 8 days left for two requests of 5.
            app(LeaveBalance::class)->adjust($this->user(RoleName::HrAdmin), $employee, -4, 'Correction for the concurrency test.');

            $outcomes = $this->race([
                ['approve', $supervisors[0]->id, "leave:$first->id"],
                ['approve', $supervisors[1]->id, "leave:$second->id"],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $period = $this->period($employee);
            $this->assertSame(5, $period->used, "Round $round");
            $this->assertSame(3, $period->remaining(), "Round $round");
            $this->assertSame(1, $this->deductions($employee), "Round $round");
            $this->assertSame(1, LeaveRequest::query()->where('user_id', $employee->id)->where('status', RequestStatus::Pending->value)->count(), "Round $round");
            $this->assertSame(1, DB::table('audit_logs')->where('event', AuditEvent::RequestDecisionRefused->value)->whereIn('subject_id', [$first->id, $second->id])->count(), "Round $round");
        }
    }

    public function test_two_approvals_waiting_for_the_same_future_period_never_exceed_it(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $supervisors] = $this->team(2);
            // Both requests lie in the next period, which does not exist yet (the anchor is today).
            $monday = CarbonImmutable::today()->addYearNoOverflow()->next(CarbonImmutable::MONDAY);
            $requests = [];
            foreach ([0, 1] as $week) {
                $requests[] = app(LeaveRequests::class)->submit($employee, [
                    'category' => 'annual',
                    'starts_on' => $monday->addWeeks($week)->toDateString(),
                    'ends_on' => $monday->addWeeks($week)->addDay()->toDateString(),
                    'reason' => 'Concurrent decision test.',
                ]);
            }
            // 7 days each, together 14 of the 12 the projected period will have (written directly: a submission
            // would have refused the second).
            DB::table('leave_request_allocations')->whereIn('leave_request_id', [$requests[0]->id, $requests[1]->id])->update(['days' => 7]);

            $outcomes = $this->race([
                ['approve', $supervisors[0]->id, "leave:{$requests[0]->id}"],
                ['approve', $supervisors[1]->id, "leave:{$requests[1]->id}"],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $this->assertSame(7, app(LeaveBalance::class)->deferredDays($employee, $monday->subMonth(), $monday->addYear()), "Round $round");
        }
    }

    public function test_approve_against_an_hr_adjustment_never_overspends(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, [$supervisor]] = $this->team(1);
            $request = $this->leave($employee, 1);
            $admin = $this->user(RoleName::HrAdmin);

            // Either fits alone: 5 approved leaves 7, and removing 9 leaves 3. Not both.
            $outcomes = $this->race([
                ['approve', $supervisor->id, "leave:$request->id"],
                ['adjust', $employee->id, "$admin->id:-9"],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $period = $this->period($employee);
            $this->assertGreaterThanOrEqual(0, $period->remaining(), "Round $round");

            // The ledger tells the same story as the period.
            $entries = LeaveBalanceEntry::query()->where('leave_period_id', $period->id)->orderBy('id')->get();
            $this->assertSame($period->remaining(), $entries->last()->after, "Round $round");
            $this->assertSame($period->remaining(), (int) $entries->sum('change'), "Round $round");
        }
    }

    public function test_a_decider_losing_authority_mid_flight_is_refused_or_decided_first(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            // Two supervisors each, so the guard lets one of them go.
            [$suspended, [$s1]] = $this->team(1);
            [$detached, [$s2]] = $this->team(1);
            foreach ([[$s1, $suspended], [$s2, $detached]] as [$extra, $employee]) {
                $other = $this->user(RoleName::Approver);
                DB::table('supervisor_user')->insert(['supervisor_id' => $other->id, 'subordinate_id' => $employee->id, 'created_at' => now()]);
            }
            $admin = $this->user(RoleName::HrAdmin);
            $first = $this->leave($suspended, 1);
            $second = $this->leave($detached, 1);

            $outcomes = $this->race([
                ['approve', $s1->id, "leave:$first->id"],
                ['suspend', $s1->id, (string) $admin->id],
                ['approve', $s2->id, "leave:$second->id"],
                ['detach', $detached->id, "$admin->id:$s2->id"],
            ]);

            // The account changes always go through; each approval either came first or was refused.
            $this->assertTrue($outcomes[1]['won'] && $outcomes[3]['won'], "Round $round");

            foreach ([[$outcomes[0], $first, AuditEvent::UserSuspended], [$outcomes[2], $second, AuditEvent::SupervisorDetached]] as [$outcome, $request, $event]) {
                $request->refresh();
                $change = DB::table('audit_logs')->where('event', $event->value)->where('actor_id', $admin->id)->latest('id')->value('id');

                if ($outcome['won']) {
                    $this->assertSame(RequestStatus::Approved, $request->status, "Round $round");
                    $decision = DB::table('audit_logs')->where('event', AuditEvent::LeaveRequestApproved->value)->where('subject_id', $request->id)->value('id');
                    $this->assertLessThan($change, $decision, "Round $round: the approval must have happened before the authority was lost.");
                } else {
                    $this->assertTrue($outcome['refused'], "Round $round");
                    $this->assertSame(RequestStatus::Pending, $request->status, "Round $round");
                    $this->assertSame(1, DB::table('audit_logs')->where('event', AuditEvent::RequestDecisionRefused->value)->where('subject_id', $request->id)->count());
                }
            }
        }
    }

    public function test_only_one_decider_takes_over_an_expired_soft_lock(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $supervisors] = $this->team(4);
            $request = $this->leave($employee, 1);
            // The first supervisor's lock ran out a minute ago.
            DB::table('leave_requests')->where('id', $request->id)->update([
                'review_locked_by' => $supervisors[0]->id,
                'review_locked_until' => now()->subMinute(),
            ]);

            $outcomes = $this->race([
                ['acquire', $supervisors[1]->id, "leave:$request->id"],
                ['acquire', $supervisors[2]->id, "leave:$request->id"],
                ['acquire', $supervisors[3]->id, "leave:$request->id"],
            ]);

            $this->assertSame(1, $this->wins($outcomes), "Round $round");
            $winner = $supervisors[1 + collect($outcomes)->search(fn (array $outcome) => $outcome['won'])];
            $this->assertSame($winner->id, $request->fresh()->review_locked_by, "Round $round");
        }
    }

    public function test_a_soft_lock_and_a_decision_at_once_leave_no_lock_behind(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            [$employee, $supervisors] = $this->team(2);
            $request = $this->leave($employee, 1);

            $outcomes = $this->race([
                ['approve', $supervisors[0]->id, "leave:$request->id"],
                ['acquire', $supervisors[1]->id, "leave:$request->id"],
            ]);

            $request->refresh();
            if ($outcomes[0]['won']) {
                // The decision cleared the lock, or came after a lock that it was refused by.
                $this->assertSame(RequestStatus::Approved, $request->status, "Round $round");
                $this->assertNull($request->review_locked_by, "Round $round");
            } else {
                $this->assertSame(RequestStatus::Pending, $request->status, "Round $round");
                $this->assertSame($supervisors[1]->id, $request->review_locked_by, "Round $round");
            }
        }
    }
}
