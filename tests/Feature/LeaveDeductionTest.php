<?php

namespace Tests\Feature;

use App\Actions\DecideRequest;
use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveBalancePeriod;
use App\Models\User;
use App\Support\LeaveAllocation;
use App\Support\LeaveBalances;
use App\Support\LeaveDeduction;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DecidesRequests;
use Tests\TestCase;

class LeaveDeductionTest extends TestCase
{
    use DecidesRequests, RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = $this->person(RoleName::HrAdmin);
        $this->supervisor = $this->person(RoleName::Approver);
        $this->employee = $this->linkedTo($this->supervisor);
    }

    private function open(): LeavePeriodReader
    {
        return new LeavePeriodReader($this->employee);
    }

    private function approve(mixed $request): ?string
    {
        return app(DecideRequest::class)->approve($request, $this->supervisor);
    }

    /**
     * Move the end of the open period to 17 Jun 2026, so a request can cross it.
     */
    private function resetSoon(): void
    {
        LeaveBalances::changeAnchor($this->employee, CarbonImmutable::parse('2020-06-18'), $this->admin);
    }

    public function test_approving_deducts_one_period_and_writes_the_ledger(): void
    {
        $request = $this->leave($this->employee, '2026-06-16', '2026-06-18');

        $this->assertNull($this->approve($request));

        $period = $this->open()->first();

        $this->assertSame(3, $period->used);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame($this->supervisor->id, $request->fresh()->decided_by);
        $this->assertNotNull($request->fresh()->decided_at);

        $entry = LeaveBalanceEntry::where('type', LeaveEntryType::LeaveApproved)->sole();

        $this->assertSame($request->id, $entry->leave_request_id);
        $this->assertSame($this->supervisor->id, $entry->actor_id);
        $this->assertSame([0, 3], [$entry->used_before, $entry->used_after]);
        $this->assertSame([$period->id], $request->allocations()->pluck('period_id')->unique()->all());
    }

    public function test_a_request_across_the_reset_after_the_reset_deducts_both_periods(): void
    {
        $this->resetSoon();
        $request = $this->leave($this->employee, '2026-06-16', '2026-06-19');

        $this->travelTo(CarbonImmutable::parse('2026-06-22 09:00:00'));

        $this->assertNull($this->approve($request));

        $periods = $this->employee->leavePeriods()->orderBy('start_date')->get();

        $this->assertCount(2, $periods);
        $this->assertNotNull($periods[0]->closed_at);
        $this->assertSame(2, $periods[0]->used);
        $this->assertSame(2, $periods[1]->used);
        $this->assertSame(2, LeaveBalanceEntry::where('leave_request_id', $request->id)->count());
    }

    public function test_a_closed_period_takes_days_only_with_a_ledger_entry(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->travelTo(CarbonImmutable::parse('2027-07-01 09:00:00'));
        $this->artisan('app:reset-leave-balances')->assertSuccessful();

        $this->assertNull($this->approve($request));

        $closed = $this->employee->leavePeriods()->whereNotNull('closed_at')->sole();

        $this->assertSame(1, $closed->used);
        $this->assertSame(1, $closed->entries()->where('type', LeaveEntryType::LeaveApproved)->count());
        $this->assertSame(0, $this->employee->leavePeriods()->whereNull('closed_at')->sole()->used);
    }

    public function test_an_overdue_period_is_rolled_over_by_the_approval(): void
    {
        $request = $this->leave($this->employee, '2027-06-21');

        $this->travelTo(CarbonImmutable::parse('2027-06-18 09:00:00'));

        $this->assertNull($this->approve($request));

        $this->assertSame(2, $this->employee->leavePeriods()->count());
        $this->assertSame(1, $this->employee->leavePeriods()->whereNull('closed_at')->sole()->used);
    }

    public function test_days_in_a_period_that_does_not_exist_wait_and_land_when_it_opens(): void
    {
        $request = $this->leave($this->employee, '2027-06-21', '2027-06-22');

        $this->assertNull($this->approve($request));

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(1, $this->employee->leavePeriods()->count());
        $this->assertSame(0, $this->open()->first()->used);
        $this->assertSame([null], $request->allocations()->pluck('period_id')->unique()->all());
        $this->assertSame(0, LeaveBalanceEntry::where('leave_request_id', $request->id)->count());

        $this->travelTo(CarbonImmutable::parse('2027-06-15 00:10:00'));
        $this->artisan('app:reset-leave-balances')->assertSuccessful();

        $new = $this->employee->leavePeriods()->whereNull('closed_at')->sole();

        $this->assertSame(2, $new->used);
        $this->assertSame([$new->id], $request->allocations()->pluck('period_id')->unique()->all());

        $entry = LeaveBalanceEntry::where('leave_request_id', $request->id)->sole();

        $this->assertNull($entry->actor_id);
        $this->assertSame([0, 2], [$entry->used_before, $entry->used_after]);
    }

    public function test_the_daily_check_again_does_not_deduct_twice(): void
    {
        $this->approve($this->leave($this->employee, '2027-06-21', '2027-06-22'));

        $this->travelTo(CarbonImmutable::parse('2027-06-15 00:10:00'));
        $this->artisan('app:reset-leave-balances')->assertSuccessful();
        $this->artisan('app:reset-leave-balances')->assertSuccessful();

        $this->assertSame(2, $this->employee->leavePeriods()->whereNull('closed_at')->sole()->used);
    }

    public function test_days_waiting_for_a_period_count_once_after_it_opens(): void
    {
        $this->approve($this->leave($this->employee, '2027-06-21', '2027-06-22'));

        [, $next] = LeaveAllocation::windows($this->employee->fresh());
        $this->assertSame(10, LeaveAllocation::available($this->employee, $next));

        $this->travelTo(CarbonImmutable::parse('2027-06-15 00:10:00'));

        [$open] = LeaveAllocation::windows($this->employee->fresh());

        $this->assertSame(10, LeaveAllocation::available($this->employee, $open));
    }

    public function test_a_period_not_yet_open_refuses_more_than_twelve_approved_days(): void
    {
        // Submitting refuses a 13th day, so a pending day is moved there to prove approval checks it alone.
        $second = $this->leave($this->employee, '2026-06-16');
        $this->assertNull($this->approve($this->leave($this->employee, '2027-06-21', '2027-07-06')));
        $second->allocations()->update(['work_date' => '2027-07-07']);

        $message = $this->approve($second);

        $this->assertStringContainsString('which has 0 left', $message);
        $this->assertSame(RequestStatus::Pending, $second->fresh()->status);
    }

    public function test_not_enough_balance_at_approval_keeps_it_pending_with_a_clear_message(): void
    {
        $request = $this->leave($this->employee, '2026-06-15', '2026-06-19');

        LeaveBalances::adjust($this->employee, 0, 8, 'taken elsewhere', $this->admin);

        $message = $this->approve($request);

        $this->assertStringContainsString('needs 5 working days', $message);
        $this->assertStringContainsString('15 Jun 2026 to 14 Jun 2027, which has 4 left', $message);
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(8, $this->open()->first()->used);
        $this->assertNull($request->fresh()->decided_by);

        $entry = AuditLog::where('event', AuditEvent::RequestApprovalRefused->value)->sole();

        $this->assertSame($this->supervisor->id, $entry->actor_id);
        $this->assertSame(5, $entry->metadata['needed']);
    }

    public function test_exactly_what_is_left_is_enough(): void
    {
        $request = $this->leave($this->employee, '2026-06-15', '2026-06-19');

        LeaveBalances::adjust($this->employee, 0, 7, 'taken elsewhere', $this->admin);

        $this->assertNull($this->approve($request));
        $this->assertSame(12, $this->open()->first()->used);
    }

    public function test_a_rejection_is_still_possible_after_that(): void
    {
        $request = $this->leave($this->employee, '2026-06-15', '2026-06-19');

        LeaveBalances::adjust($this->employee, 0, 10, 'taken elsewhere', $this->admin);

        $this->approve($request);

        $this->assertNull(app(DecideRequest::class)->reject($request, $this->supervisor, 'No balance left'));
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_when_one_part_does_not_fit_no_part_is_deducted(): void
    {
        $this->resetSoon();
        $request = $this->leave($this->employee, '2026-06-16', '2026-06-19');

        $this->travelTo(CarbonImmutable::parse('2026-06-22 09:00:00'));
        $this->artisan('app:reset-leave-balances')->assertSuccessful();

        $new = $this->employee->leavePeriods()->whereNull('closed_at')->sole();
        LeaveBalances::adjust($this->employee, 0, 11, 'taken elsewhere', $this->admin);

        $this->assertNotNull($this->approve($request));

        $this->assertSame(0, $this->employee->leavePeriods()->whereNotNull('closed_at')->sole()->used);
        $this->assertSame(11, $new->fresh()->used);
        $this->assertSame([null], $request->allocations()->pluck('period_id')->unique()->all());
    }

    public function test_a_category_that_does_not_deduct_leaves_the_balance_alone(): void
    {
        $request = $this->leave($this->employee, '2026-06-16', '2026-06-18', 'sick');

        $this->assertNull($this->approve($request));

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(0, $this->open()->first()->used);
        $this->assertSame(0, LeaveBalanceEntry::where('type', LeaveEntryType::LeaveApproved)->count());
    }

    public function test_a_rejection_leaves_the_balance_alone_and_frees_the_days(): void
    {
        $request = $this->leave($this->employee, '2026-06-15', '2026-06-30');

        $this->assertNull(app(DecideRequest::class)->reject($request, $this->supervisor, 'Too busy this month'));

        $this->assertSame(0, $this->open()->first()->used);
        $this->assertSame(12, $this->leave($this->employee, '2026-07-01', '2026-07-16')->working_days);
    }

    public function test_approving_twice_deducts_once(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->approve($request);

        $this->assertStringContainsString('Already approved', $this->approve($request));
        $this->assertSame(1, $this->open()->first()->used);
    }

    public function test_a_day_mapped_before_a_boundary_moved_is_not_counted_again(): void
    {
        $request = $this->leave($this->employee, '2026-07-01');
        $this->approve($request);

        // The day already taken by the open period now lies in the next one.
        $this->resetSoon();

        [, $next] = LeaveAllocation::windows($this->employee->fresh());

        $this->assertTrue($next->covers('2026-07-01'));
        $this->assertSame(12, LeaveAllocation::available($this->employee, $next));

        $pending = $this->leave($this->employee, '2026-07-02');

        $this->assertSame(0, LeaveDeduction::plan($pending, $this->employee->fresh())[0]['window']->used);
    }

    public function test_a_day_further_than_the_next_period_lands_in_its_own_window(): void
    {
        $request = $this->leave($this->employee, '2028-01-03');

        $this->resetSoon();

        $window = LeaveDeduction::plan($request, $this->employee->fresh())[0]['window'];

        $this->assertSame('2027-06-18', $window->start->toDateString());
        $this->assertSame('2028-06-17', $window->end->toDateString());
    }

    public function test_opening_a_period_takes_only_the_days_inside_it(): void
    {
        $near = $this->leave($this->employee, '2026-07-01');
        $far = $this->leave($this->employee, '2028-01-03');
        $this->approve($far);
        $this->approve($near);

        $this->resetSoon();
        $this->travelTo(CarbonImmutable::parse('2026-06-18 00:10:00'));
        $this->artisan('app:reset-leave-balances')->assertSuccessful();

        $open = $this->employee->leavePeriods()->whereNull('closed_at')->sole();

        $this->assertSame(0, $open->used);
        $this->assertSame([null], $far->allocations()->pluck('period_id')->unique()->all());
    }

    public function test_a_manual_reset_leaves_mapped_days_where_they_are(): void
    {
        $request = $this->leave($this->employee, '2026-09-15');
        $this->approve($request);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
        LeaveBalances::resetNow($this->employee, $this->admin, 'ui');

        $this->assertSame(1, $this->employee->leavePeriods()->whereNotNull('closed_at')->sole()->used);
        $this->assertSame(0, $this->employee->leavePeriods()->whereNull('closed_at')->sole()->used);
    }
}

/**
 * Reads the first period of a user, fresh from the database.
 */
class LeavePeriodReader
{
    public function __construct(private User $user) {}

    public function first(): LeaveBalancePeriod
    {
        return $this->user->leavePeriods()->orderBy('start_date')->first();
    }
}
