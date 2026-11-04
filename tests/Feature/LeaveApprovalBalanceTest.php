<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LeaveEntryType;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

/**
 * Leave approval and the balance (DECISIONS.md sections 5 and 6): deduction with ledger rows, mapping of
 * projected periods, deferred charges for a period that has not started, closed periods, and refusal
 * when the balance is no longer enough.
 */
class LeaveApprovalBalanceTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Family matters out of town.';

    private User $supervisor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->supervisor = $this->member(RoleName::Approver);
        $this->admin = $this->member(RoleName::HrAdmin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): User
    {
        $employee = $this->member(RoleName::Employee, $attributes);
        $this->supervise($this->supervisor, $employee);

        return $employee;
    }

    private function leave(User $user, string $start, string $end, string $category = 'annual'): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, ['category' => $category, 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON]);
    }

    private function approve(LeaveRequest $request): void
    {
        app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);
    }

    private function period(User $user, string $start): LeavePeriod
    {
        return LeavePeriod::query()->where('user_id', $user->id)->where('starts_on', $start)->sole();
    }

    /**
     * @return list<string>
     */
    private function refusal(LeaveRequest $request): array
    {
        try {
            $this->approve($request);
        } catch (ValidationException $exception) {
            return $exception->errors()['decision'];
        }

        $this->fail('The approval was accepted.');
    }

    public function test_approval_deducts_the_days_and_writes_a_ledger_row(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-09', '2026-11-13');

        $this->approve($request);

        $period = $this->period($employee, '2026-11-04');
        $this->assertSame(5, $period->used);
        $this->assertSame(7, $period->remaining());

        $entry = LeaveBalanceEntry::query()->where('type', LeaveEntryType::Deducted->value)->sole();
        $this->assertSame([$period->id, $employee->id, $this->supervisor->id, $request->id, -5, 12, 7, null], [
            $entry->leave_period_id, $entry->user_id, $entry->actor_id, $entry->leave_request_id, $entry->change, $entry->before, $entry->after, $entry->reason,
        ]);

        $allocation = $request->allocations()->sole();
        $this->assertSame($period->id, $allocation->leave_period_id);
        $this->assertSame('2026-11-04 10:00:00', $allocation->charged_at->toDateTimeString());

        $audit = AuditLog::query()->where('event', AuditEvent::LeaveRequestApproved->value)->sole();
        $this->assertEquals([
            'requester_id' => $employee->id,
            'note_given' => false,
            'charged' => [['period_starts_on' => '2026-11-04', 'days' => 5, 'deferred' => false]],
        ], $audit->metadata);

        // Available on the requester's own screen: 12 - 5 used - 0 pending.
        $this->assertSame(7, app(LeaveRequests::class)->slots($employee)[0]->available());
    }

    public function test_non_deducting_leave_and_rejections_leave_the_balance_alone(): void
    {
        $employee = $this->employee();
        $sick = $this->leave($employee, '2026-11-09', '2026-11-10', 'sick');
        $annual = $this->leave($employee, '2026-11-16', '2026-11-17');

        $this->approve($sick);
        app(Decisions::class)->reject($this->supervisor, 'leave', $annual->id, 'Too many people away.');

        $this->assertSame(0, $this->period($employee, '2026-11-04')->used);
        $this->assertSame(0, LeaveBalanceEntry::query()->where('type', LeaveEntryType::Deducted->value)->count());
        $this->assertNull($annual->allocations()->sole()->charged_at);
        $this->assertArrayNotHasKey('charged', AuditLog::query()->where('event', AuditEvent::LeaveRequestApproved->value)->sole()->metadata);
    }

    public function test_insufficient_balance_at_approval_keeps_the_request_pending_and_is_audited(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-09', '2026-11-13');

        // HR removes days after the submission: only 3 remain for a 5-day request.
        app(LeaveBalance::class)->adjust($this->admin, $employee, -9, 'Correction of the opening balance.');

        $this->assertSame(['Not enough leave balance in the period 4 Nov 2026 – 3 Nov 2027: this request needs 5 working days, 3 remain. The request stays pending.'], $this->refusal($request));

        $request->refresh();
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertNull($request->decided_by);
        $this->assertNull($request->allocations()->sole()->charged_at);
        $this->assertSame(0, $this->period($employee, '2026-11-04')->used);
        $this->assertSame(0, LeaveBalanceEntry::query()->where('type', LeaveEntryType::Deducted->value)->count());

        $audit = AuditLog::query()->where('event', AuditEvent::RequestDecisionRefused->value)->sole();
        $this->assertSame($this->supervisor->id, $audit->actor_id);
        $this->assertEquals(['decision' => 'approved', 'reason' => 'insufficient_balance'], $audit->metadata);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::LeaveRequestApproved->value)->count());
    }

    public function test_two_pending_requests_compete_for_the_last_days(): void
    {
        $employee = $this->employee();
        $first = $this->leave($employee, '2026-11-09', '2026-11-13');
        $second = $this->leave($employee, '2026-11-16', '2026-11-20');
        app(LeaveBalance::class)->adjust($this->admin, $employee, -4, 'Correction of the opening balance.');

        $this->approve($first);
        $this->assertCount(1, $this->refusal($second));

        $this->assertSame(5, $this->period($employee, '2026-11-04')->used);
        $this->assertSame(RequestStatus::Pending, $second->fresh()->status);
    }

    public function test_exactly_the_remaining_days_can_be_approved(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-09', '2026-11-13');
        app(LeaveBalance::class)->adjust($this->admin, $employee, -7, 'Correction of the opening balance.');

        $this->approve($request);

        $this->assertSame(0, $this->period($employee, '2026-11-04')->remaining());
    }

    public function test_a_part_in_the_next_period_is_charged_when_that_period_opens(): void
    {
        // Current period 2026-01-15 to 2027-01-14; the next one starts 2027-01-15.
        $employee = $this->employee(['leave_anchor_date' => '2026-01-15']);
        $request = $this->leave($employee, '2027-01-11', '2027-01-19');

        $this->approve($request);

        $current = $this->period($employee, '2026-01-15');
        $this->assertSame(4, $current->used);
        [$now, $later] = $request->allocations()->get()->all();
        $this->assertNotNull($now->charged_at);
        $this->assertNull($later->charged_at);
        $this->assertNull($later->leave_period_id);
        $this->assertSame(1, LeavePeriod::query()->where('user_id', $employee->id)->count(), 'No period is opened before it starts.');

        // The projected next period already counts the approved days as used.
        $next = app(LeaveRequests::class)->slots($employee)[1];
        $this->assertSame([3, 0, 9], [$next->used, $next->pending, $next->available()]);

        $this->assertEquals([
            ['period_starts_on' => '2026-01-15', 'days' => 4, 'deferred' => false],
            ['period_starts_on' => '2027-01-15', 'days' => 3, 'deferred' => true],
        ], AuditLog::query()->where('event', AuditEvent::LeaveRequestApproved->value)->sole()->metadata['charged']);

        // The daily job opens the period and charges the waiting days, in the approver's name.
        $this->travelTo(CarbonImmutable::parse('2027-01-15 00:05'));
        app(LeaveBalance::class)->syncAll();

        $opened = $this->period($employee, '2027-01-15');
        $this->assertSame(3, $opened->used);
        $later->refresh();
        $this->assertSame($opened->id, $later->leave_period_id);
        $this->assertNotNull($later->charged_at);

        $entries = LeaveBalanceEntry::query()->where('leave_period_id', $opened->id)->orderBy('id')->get();
        $this->assertSame([LeaveEntryType::Opened, LeaveEntryType::Deducted], $entries->pluck('type')->all());
        $this->assertSame([$this->supervisor->id, $request->id, -3, 12, 9], [
            $entries[1]->actor_id, $entries[1]->leave_request_id, $entries[1]->change, $entries[1]->before, $entries[1]->after,
        ]);

        // A second run changes nothing.
        app(LeaveBalance::class)->syncAll();
        $this->assertSame(3, $opened->fresh()->used);
        $this->assertSame(2, LeaveBalanceEntry::query()->where('leave_period_id', $opened->id)->count());
    }

    public function test_a_waiting_part_of_a_request_cancelled_or_still_pending_is_not_charged_on_opening(): void
    {
        $employee = $this->employee(['leave_anchor_date' => '2026-01-15']);
        $this->leave($employee, '2027-01-18', '2027-01-19');

        $this->travelTo(CarbonImmutable::parse('2027-01-15 00:05'));
        app(LeaveBalance::class)->syncAll();

        $this->assertSame(0, $this->period($employee, '2027-01-15')->used);
    }

    public function test_a_period_that_started_but_was_not_opened_yet_is_opened_by_the_approval(): void
    {
        $employee = $this->employee(['leave_anchor_date' => '2026-01-15']);
        $request = $this->leave($employee, '2027-01-11', '2027-01-19');

        // The daily job has not run since the reset date.
        $this->travelTo(CarbonImmutable::parse('2027-01-16 09:00'));
        $this->approve($request);

        $opened = $this->period($employee, '2027-01-15');
        $this->assertSame(3, $opened->used);
        $this->assertSame(4, $this->period($employee, '2026-01-15')->used);
        $this->assertSame(2, $request->allocations()->whereNotNull('charged_at')->count());
        $this->assertSame(
            [$this->supervisor->id],
            LeaveBalanceEntry::query()->where('leave_period_id', $opened->id)->where('type', LeaveEntryType::Deducted->value)->pluck('actor_id')->all(),
        );
    }

    public function test_a_closed_period_is_charged_through_the_approval(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-09', '2026-11-10');

        // A week later HR resets the period: the old one ends on 10 November and is closed.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00'));
        app(LeaveBalance::class)->reset($this->admin, $employee, 'New contract from today on.');

        $this->approve($request);

        $closed = $this->period($employee, '2026-11-04');
        $this->assertSame('2026-11-10', $closed->ends_on->toDateString());
        $this->assertSame(2, $closed->used);
        $this->assertSame(0, $this->period($employee, '2026-11-11')->used);
    }

    public function test_after_a_reset_a_part_is_charged_to_the_period_that_now_holds_its_dates(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-16', '2026-11-17');

        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00'));
        app(LeaveBalance::class)->reset($this->admin, $employee, 'New contract from today on.');

        $this->approve($request);

        $this->assertSame(0, $this->period($employee, '2026-11-04')->used);
        $this->assertSame(2, $this->period($employee, '2026-11-11')->used);
    }

    public function test_a_reset_after_an_approval_does_not_charge_the_same_days_again(): void
    {
        $employee = $this->employee();
        $request = $this->leave($employee, '2026-11-16', '2026-11-17');
        $this->approve($request);

        // The new period opened by the reset holds the approved dates, which were already charged to the old one.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00'));
        app(LeaveBalance::class)->reset($this->admin, $employee, 'New contract from today on.');

        $this->assertSame(2, $this->period($employee, '2026-11-04')->used);
        $this->assertSame(0, $this->period($employee, '2026-11-11')->used);
        $this->assertSame(1, LeaveBalanceEntry::query()->where('type', LeaveEntryType::Deducted->value)->count());
        $this->assertSame(0, app(LeaveRequests::class)->slots($employee)[1]->used);
    }

    public function test_a_waiting_part_beyond_a_shortened_next_period_is_kept_for_the_period_after(): void
    {
        $employee = $this->employee(['leave_anchor_date' => '2026-01-15']);
        $request = $this->leave($employee, '2027-01-18', '2027-01-19');

        // A new anchor of 17 January makes the next period run only from 15 to 16 January 2027.
        app(LeaveBalance::class)->changeAnchor($this->admin, $employee, '2025-01-17');
        $this->approve($request);

        $this->assertEquals(
            [['period_starts_on' => '2027-01-17', 'days' => 2, 'deferred' => true]],
            AuditLog::query()->where('event', AuditEvent::LeaveRequestApproved->value)->sole()->metadata['charged'],
        );

        $this->travelTo(CarbonImmutable::parse('2027-01-17 00:05'));
        app(LeaveBalance::class)->syncAll();
        $this->assertSame(0, $this->period($employee, '2027-01-15')->used);
        $this->assertSame(2, $this->period($employee, '2027-01-17')->used);
    }

    public function test_a_waiting_part_is_refused_when_the_projected_period_is_already_full(): void
    {
        $employee = $this->employee(['leave_anchor_date' => '2026-01-15']);
        $pending = $this->leave($employee, '2027-01-18', '2027-01-22');

        // Another request already approved for 10 days of the next period (written directly: a submission
        // would have counted the pending one).
        $other = $this->leave($employee, '2027-02-01', '2027-02-02');
        DB::table('leave_requests')->where('id', $other->id)->update(['status' => RequestStatus::Approved->value]);
        DB::table('leave_request_allocations')->where('leave_request_id', $other->id)->update(['days' => 10]);

        $this->assertSame(
            ['Not enough leave balance in the period 15 Jan 2027 – 14 Jan 2028: this request needs 5 working days, 2 remain. The request stays pending.'],
            $this->refusal($pending),
        );
        $this->assertSame(RequestStatus::Pending, $pending->fresh()->status);
    }

    public function test_the_ledger_shows_the_approval_label(): void
    {
        $this->assertSame('Leave approved', LeaveEntryType::Deducted->label());
    }
}
