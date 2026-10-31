<?php

namespace Tests\Feature;

use App\Actions\SubmitLeaveRequest;
use App\Actions\SubmitOvertimeRequest;
use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Support\LeaveAllocation;
use App\Support\LeaveBalances;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Database\Seeders\LeaveCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\MakesRequests;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use MakesRequests, RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // A Monday. The employee's first period runs to 14 Jun 2027, the next to 14 Jun 2028.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = $this->person(RoleName::HrAdmin);
        $this->employee = $this->supervisedEmployee();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function submit(array $input, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->employee)->from(route('requests.leave.create'))->post(route('requests.leave.store'), $input);
    }

    private function assertRefused(TestResponse $response, string $field, string $message): void
    {
        $response->assertSessionHasErrors($field);
        $this->assertStringContainsString($message, session('errors')->first($field));
        $this->assertSame(0, LeaveRequest::query()->where('reason', 'like', '%refused%')->count());
    }

    private function holdDays(string $start, string $end): LeaveRequest
    {
        return app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput($start, $end), null);
    }

    /**
     * Move the end of the open period to 17 Jun 2026, so a request can cross it.
     */
    private function resetSoon(): void
    {
        LeaveBalances::changeAnchor($this->employee, CarbonImmutable::parse('2020-06-18'), $this->admin);
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function ranges(): array
    {
        return [
            'one weekday' => ['2026-06-15', '2026-06-15', ['2026-06-15']],
            'a full week' => ['2026-06-15', '2026-06-19', ['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18', '2026-06-19']],
            'across a weekend' => ['2026-06-19', '2026-06-22', ['2026-06-19', '2026-06-22']],
            'saturday' => ['2026-06-20', '2026-06-20', []],
            'sunday' => ['2026-06-21', '2026-06-21', []],
            'around a holiday' => ['2026-06-23', '2026-06-25', ['2026-06-23', '2026-06-25']],
            'only the holiday' => ['2026-06-24', '2026-06-24', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('ranges')]
    public function test_working_days_are_monday_to_friday_without_holidays(string $start, string $end, array $expected): void
    {
        PublicHoliday::create(['date' => '2026-06-24', 'name' => 'Holiday']);

        $this->assertSame($expected, WorkingDays::between(CarbonImmutable::parse($start), CarbonImmutable::parse($end)));
    }

    public function test_a_holiday_outside_the_range_changes_nothing(): void
    {
        PublicHoliday::create(['date' => '2026-06-22', 'name' => 'Holiday']);

        $this->assertCount(5, WorkingDays::between(CarbonImmutable::parse('2026-06-15'), CarbonImmutable::parse('2026-06-19')));
    }

    public function test_a_request_is_created_pending_with_its_days_frozen_and_audited(): void
    {
        $this->submit($this->leaveInput('2026-06-19', '2026-06-22'))->assertRedirect();

        $request = LeaveRequest::sole();

        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame(2, $request->working_days);
        $this->assertSame(['2026-06-19', '2026-06-22'], $request->allocations()->orderBy('work_date')->pluck('work_date')->map->toDateString()->all());
        $this->assertNull($request->decided_by);

        $entry = AuditLog::where('event', AuditEvent::LeaveRequestCreated->value)->sole();

        $this->assertSame($this->employee->id, $entry->actor_id);
        $this->assertSame(2, $entry->metadata['working_days']);
        $this->assertSame('annual', $entry->metadata['category']);
        $this->assertStringNotContainsString(self::VALID_REASON, json_encode($entry->metadata));
    }

    public function test_used_is_not_touched(): void
    {
        $this->holdDays('2026-06-15', '2026-06-19');

        $this->assertSame(0, $this->employee->leavePeriods()->sole()->used);
    }

    public function test_a_category_that_does_not_deduct_ignores_the_balance_and_allocates_nothing(): void
    {
        $this->holdDays('2026-06-15', '2026-06-30');

        $this->submit($this->leaveInput('2026-07-01', '2026-08-31', 'sick'))->assertSessionHasNoErrors();

        $request = LeaveRequest::query()->latest('id')->first();

        $this->assertSame(0, $request->allocations()->count());
        $this->assertGreaterThan(12, $request->working_days);
    }

    public function test_an_approver_can_request_through_any_active_hr_admin(): void
    {
        $approver = $this->person(RoleName::Approver);

        $this->submit($this->leaveInput('2026-06-15'), $approver)->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::whereBelongsTo($approver)->count());
    }

    public function test_an_employee_without_a_supervisor_is_refused(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2026-06-15'), $this->person(RoleName::Employee)), 'form', 'no active supervisor');
    }

    public function test_an_hr_admin_needs_a_supervisor_too(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2026-06-15'), $this->admin), 'form', 'no active supervisor');
    }

    public function test_a_supervisor_who_is_no_longer_active_does_not_count(): void
    {
        DB::table('users')->where('id', $this->employee->supervisors()->value('users.id'))->update(['status' => UserStatus::Suspended->value]);

        $this->assertRefused($this->submit($this->leaveInput('2026-06-15')), 'form', 'no active supervisor');
    }

    public function test_an_approver_is_refused_when_there_is_no_active_hr_admin(): void
    {
        $approver = $this->person(RoleName::Approver);
        DB::table('users')->where('id', $this->admin->id)->update(['status' => UserStatus::Suspended->value]);

        $this->assertRefused($this->submit($this->leaveInput('2026-06-15'), $approver), 'form', 'no active HR Admin');
    }

    public function test_an_inactive_requester_is_refused_by_the_action(): void
    {
        $this->employee->forceFill(['status' => UserStatus::Pending])->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not active');

        app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput('2026-06-15'), null);
    }

    public function test_the_action_refuses_someone_without_the_permission(): void
    {
        DB::table('role_has_permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);

        app(SubmitLeaveRequest::class)->handle($this->employee->fresh(), $this->leaveInput('2026-06-15'), null);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'no reason' => [['reason' => ''], 'reason'],
            'blank reason' => [['reason' => '          '], 'reason'],
            'reason of nine characters' => [['reason' => '123456789'], 'reason'],
            'reason over 1000 characters' => [['reason' => str_repeat('a', 1001)], 'reason'],
            'no start date' => [['start_date' => ''], 'start_date'],
            'bad start date' => [['start_date' => '15/06/2026'], 'start_date'],
            'impossible date' => [['start_date' => '2026-02-30'], 'start_date'],
            'no category' => [['leave_category_id' => ''], 'leave_category_id'],
            'unknown category' => [['leave_category_id' => 999_999], 'leave_category_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_refused(array $overrides, string $field): void
    {
        $this->submit($this->leaveInput('2026-06-15', overrides: $overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_reason_of_ten_and_of_1000_characters_is_accepted(): void
    {
        $this->submit($this->leaveInput('2026-06-15', overrides: ['reason' => str_repeat('a', 10)]))->assertSessionHasNoErrors();
        $this->submit($this->leaveInput('2026-06-16', overrides: ['reason' => str_repeat('a', 1000)]))->assertSessionHasNoErrors();
    }

    public function test_the_start_cannot_be_before_today_in_wib(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2026-06-14')), 'start_date', 'cannot be before today');

        $this->travelTo(CarbonImmutable::parse('2026-06-16 00:30:00', 'Asia/Jakarta'));

        $this->submit($this->leaveInput('2026-06-16'))->assertSessionHasNoErrors();
        $this->assertRefused($this->submit($this->leaveInput('2026-06-15')), 'start_date', 'cannot be before today');
    }

    public function test_today_itself_is_allowed(): void
    {
        $this->submit($this->leaveInput('2026-06-15'))->assertSessionHasNoErrors();
    }

    public function test_the_end_cannot_be_before_the_start(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2026-06-16', '2026-06-15')), 'end_date', 'cannot be before the start date');
    }

    public function test_a_range_without_a_working_day_is_refused(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2026-06-20', '2026-06-21')), 'start_date', 'no working days');
        $this->assertRefused($this->submit($this->leaveInput('2026-06-20', '2026-06-21', 'sick')), 'start_date', 'no working days');
    }

    public function test_an_inactive_category_is_refused(): void
    {
        DB::table('leave_categories')->where('code', 'other')->update(['is_active' => false]);

        $this->assertRefused($this->submit($this->leaveInput('2026-06-15', code: 'other')), 'leave_category_id', 'available category');
    }

    public function test_seeding_again_keeps_a_category_switched_off_and_adds_no_rows(): void
    {
        DB::table('leave_categories')->where('code', 'other')->update(['is_active' => false]);

        $this->seed(LeaveCategorySeeder::class);
        $this->seed(LeaveCategorySeeder::class);

        $this->assertSame(3, DB::table('leave_categories')->count());
        $this->assertFalse($this->category('other')->is_active);
        $this->assertTrue($this->category('annual')->deducts_balance);
        $this->assertFalse($this->category('sick')->deducts_balance);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlapping(): array
    {
        return [
            'same days' => ['2026-06-16', '2026-06-18'],
            'first day touches the last' => ['2026-06-18', '2026-06-19'],
            'last day touches the first' => ['2026-06-15', '2026-06-16'],
            'inside' => ['2026-06-17', '2026-06-17'],
            'around' => ['2026-06-15', '2026-06-19'],
        ];
    }

    #[DataProvider('overlapping')]
    public function test_overlapping_leave_is_refused_and_named(string $start, string $end): void
    {
        $this->holdDays('2026-06-16', '2026-06-18');

        $this->assertRefused(
            $this->submit($this->leaveInput($start, $end, 'sick')),
            'start_date',
            'overlaps your pending leave request of 16 Jun 2026 to 18 Jun 2026',
        );
    }

    public function test_leave_next_to_other_leave_is_fine(): void
    {
        $this->holdDays('2026-06-16', '2026-06-16');

        $this->submit($this->leaveInput('2026-06-15'))->assertSessionHasNoErrors();
        $this->submit($this->leaveInput('2026-06-17'))->assertSessionHasNoErrors();
    }

    public function test_overlap_counts_calendar_days_even_without_a_working_day_in_common(): void
    {
        $this->holdDays('2026-06-19', '2026-06-20');

        $this->assertRefused($this->submit($this->leaveInput('2026-06-20', '2026-06-22')), 'start_date', 'overlaps');
    }

    public function test_an_approved_request_holds_its_dates(): void
    {
        $this->holdDays('2026-06-16', '2026-06-16')->forceFill(['status' => RequestStatus::Approved])->save();

        $this->assertRefused($this->submit($this->leaveInput('2026-06-16')), 'start_date', 'approved leave request');
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function freeingStatuses(): array
    {
        return ['rejected' => [RequestStatus::Rejected], 'cancelled' => [RequestStatus::Cancelled]];
    }

    #[DataProvider('freeingStatuses')]
    public function test_a_rejected_or_cancelled_request_frees_its_dates_and_days(RequestStatus $status): void
    {
        $this->holdDays('2026-06-15', '2026-06-30')->forceFill(['status' => $status])->save();

        $this->submit($this->leaveInput('2026-06-15', '2026-06-30'))->assertSessionHasNoErrors();
    }

    public function test_another_users_request_does_not_collide_and_is_never_named(): void
    {
        $this->holdDays('2026-06-15', '2026-06-30');

        $this->submit($this->leaveInput('2026-06-15', '2026-06-30'), $this->supervisedEmployee())->assertSessionHasNoErrors();
    }

    public function test_leave_on_a_date_touched_by_overtime_is_refused(): void
    {
        app(SubmitOvertimeRequest::class)->handle($this->employee, $this->overtimeInput('2026-06-16T22:00', '2026-06-17T02:00'), null);

        $this->assertRefused($this->submit($this->leaveInput('2026-06-17')), 'start_date', 'touches your pending overtime request of 16 Jun 2026, 22:00');
        $this->assertRefused($this->submit($this->leaveInput('2026-06-16')), 'start_date', 'touches');
    }

    public function test_overtime_ending_at_midnight_leaves_the_next_date_free(): void
    {
        app(SubmitOvertimeRequest::class)->handle($this->employee, $this->overtimeInput('2026-06-16T18:00', '2026-06-17T00:00'), null);

        $this->submit($this->leaveInput('2026-06-17'))->assertSessionHasNoErrors();
    }

    public function test_leave_on_the_day_before_overtime_is_fine(): void
    {
        app(SubmitOvertimeRequest::class)->handle($this->employee, $this->overtimeInput('2026-06-17T00:00', '2026-06-17T03:00'), null);

        $this->submit($this->leaveInput('2026-06-16'))->assertSessionHasNoErrors();
    }

    public function test_cancelled_overtime_does_not_block_leave(): void
    {
        app(SubmitOvertimeRequest::class)
            ->handle($this->employee, $this->overtimeInput('2026-06-16T18:00', '2026-06-16T20:00'), null)
            ->forceFill(['status' => RequestStatus::Cancelled])->save();

        $this->submit($this->leaveInput('2026-06-16'))->assertSessionHasNoErrors();
    }

    public function test_all_twelve_days_can_be_requested(): void
    {
        $this->submit($this->leaveInput('2026-06-15', '2026-06-30'))->assertSessionHasNoErrors();

        $this->assertSame(12, LeaveRequest::sole()->working_days);
    }

    public function test_more_days_than_the_period_has_are_refused(): void
    {
        $this->assertRefused(
            $this->submit($this->leaveInput('2026-06-15', '2026-07-01')),
            'start_date',
            'needs 13 working days from your leave period 15 Jun 2026 to 14 Jun 2027, which has 12 left',
        );
    }

    public function test_pending_days_come_off_what_is_left(): void
    {
        $this->holdDays('2026-06-15', '2026-06-26');

        $this->assertRefused($this->submit($this->leaveInput('2026-07-01', '2026-07-03')), 'start_date', 'which has 2 left after your pending requests');

        $this->submit($this->leaveInput('2026-07-01', '2026-07-02'))->assertSessionHasNoErrors();
    }

    public function test_used_days_and_the_entitlement_count(): void
    {
        LeaveBalances::adjust($this->employee, 3, 5, 'history', $this->admin);

        $this->assertRefused($this->submit($this->leaveInput('2026-06-15', '2026-06-30')), 'start_date', 'which has 10 left');

        $this->submit($this->leaveInput('2026-06-15', '2026-06-26'))->assertSessionHasNoErrors();
    }

    public function test_an_approved_request_does_not_count_twice_in_an_open_period(): void
    {
        LeaveBalances::adjust($this->employee, 0, 2, 'approved days', $this->admin);
        $this->holdDays('2026-06-15', '2026-06-16')->forceFill(['status' => RequestStatus::Approved])->save();

        [$open] = LeaveAllocation::windows($this->employee->fresh());

        $this->assertSame(10, LeaveAllocation::available($this->employee, $open));
    }

    public function test_a_projected_period_counts_approved_days_because_it_has_no_used_yet(): void
    {
        [, $next] = LeaveAllocation::windows($this->employee->fresh());

        $this->holdDays('2027-06-15', '2027-06-16')->forceFill(['status' => RequestStatus::Approved])->save();
        $this->holdDays('2027-06-17', '2027-06-17');

        $this->assertTrue($next->projected);
        $this->assertSame(9, LeaveAllocation::available($this->employee, $next));
    }

    public function test_a_request_across_the_reset_keeps_each_part_in_its_period(): void
    {
        $this->resetSoon();

        $this->submit($this->leaveInput('2026-06-16', '2026-06-19'))->assertSessionHasNoErrors();

        $days = LeaveRequest::sole()->allocations()->pluck('work_date')->map->toDateString()->sort()->values();

        [$open, $next] = LeaveAllocation::windows($this->employee->fresh());

        $this->assertSame('2026-06-17', $open->end->toDateString());
        $this->assertSame(['2026-06-16', '2026-06-17'], $days->filter(fn (string $day) => $open->covers($day))->values()->all());
        $this->assertSame(['2026-06-18', '2026-06-19'], $days->filter(fn (string $day) => $next->covers($day))->values()->all());
        $this->assertSame(10, LeaveAllocation::available($this->employee, $open));
        $this->assertSame(10, LeaveAllocation::available($this->employee, $next));
    }

    public function test_the_old_part_of_a_request_across_the_reset_is_checked_alone(): void
    {
        $this->resetSoon();
        LeaveBalances::adjust($this->employee, 0, 11, 'history', $this->admin);

        $this->assertRefused(
            $this->submit($this->leaveInput('2026-06-16', '2026-06-19')),
            'start_date',
            'needs 2 working days from your leave period 15 Jun 2026 to 17 Jun 2026, which has 1 left',
        );
    }

    public function test_the_new_part_of_a_request_across_the_reset_is_checked_alone(): void
    {
        $this->resetSoon();
        $this->holdDays('2026-06-22', '2026-07-07');

        $this->assertRefused(
            $this->submit($this->leaveInput('2026-06-17', '2026-06-18')),
            'start_date',
            'needs 1 working days from your leave period 18 Jun 2026 to 17 Jun 2027, which has 0 left',
        );
    }

    public function test_the_furthest_allowed_day_is_the_end_of_the_next_period(): void
    {
        $this->submit($this->leaveInput('2028-06-14'))->assertSessionHasNoErrors();

        $this->assertRefused($this->submit($this->leaveInput('2028-06-15')), 'end_date', 'up to 14 Jun 2028');
    }

    public function test_the_limit_applies_to_every_category(): void
    {
        $this->assertRefused($this->submit($this->leaveInput('2028-06-15', code: 'sick')), 'end_date', 'up to 14 Jun 2028');
    }

    public function test_an_overdue_period_is_rolled_over_by_the_request_itself(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-06-16 09:00:00'));

        $this->submit($this->leaveInput('2027-06-16'))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->employee->leavePeriods()->count());
        $this->assertSame('request', AuditLog::where('event', AuditEvent::LeaveBalanceReset->value)->sole()->metadata['source']);
    }

    public function test_a_user_without_a_period_gets_one_with_their_first_request(): void
    {
        $employee = $this->supervisedEmployee();
        $employee->leavePeriods()->each(function ($period) {
            DB::table('leave_balance_entries')->where('period_id', $period->id)->delete();
            $period->delete();
        });

        $this->submit($this->leaveInput('2026-06-15'), $employee)->assertSessionHasNoErrors();

        $this->assertSame(1, $employee->leavePeriods()->count());
    }

    public function test_a_failed_request_leaves_no_roll_over_behind(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-06-16 09:00:00'));

        $this->submit($this->leaveInput('2027-06-19', '2027-06-20'))->assertSessionHasErrors('start_date');

        $this->assertSame(1, $this->employee->leavePeriods()->count());
    }

    public function test_a_manual_reset_moves_days_into_the_new_period_without_touching_the_count(): void
    {
        $this->holdDays('2026-09-15', '2026-09-16');

        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00'));
        LeaveBalances::resetNow($this->employee, $this->admin, 'ui');

        [$open] = LeaveAllocation::windows($this->employee->fresh());

        $this->assertSame('2026-09-01', $open->start->toDateString());
        $this->assertSame(10, LeaveAllocation::available($this->employee, $open));
    }

    public function test_a_holiday_added_later_does_not_change_a_request(): void
    {
        $request = $this->holdDays('2026-06-15', '2026-06-19');

        PublicHoliday::create(['date' => '2026-06-17', 'name' => 'Later']);

        $this->assertSame(5, $request->fresh()->working_days);
        $this->assertSame(5, $request->allocations()->count());
    }

    public function test_a_holiday_removed_later_does_not_change_a_request(): void
    {
        $holiday = PublicHoliday::create(['date' => '2026-06-17', 'name' => 'Removed']);
        $request = $this->holdDays('2026-06-15', '2026-06-19');

        $this->assertSame(4, $request->working_days);

        $holiday->delete();

        $this->assertSame(4, $request->fresh()->working_days);
    }

    public function test_status_and_owner_cannot_arrive_with_the_form(): void
    {
        $this->submit($this->leaveInput('2026-06-15', overrides: [
            'status' => 'approved',
            'user_id' => $this->admin->id,
            'working_days' => 1,
            'decided_by' => $this->admin->id,
        ]))->assertSessionHasNoErrors();

        $request = LeaveRequest::sole();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertNull($request->decided_by);
    }
}
