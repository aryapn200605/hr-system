<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveCategory;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAllocation;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Requests\ApprovalPath;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\WorkingDays;
use Carbon\CarbonImmutable;
use Database\Seeders\LeaveCategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Family matters out of town.';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->employee = $this->supervisedMember();
    }

    private function service(): LeaveRequests
    {
        return app(LeaveRequests::class);
    }

    private function submit(User $user, string $start, string $end, string $category = 'annual', array $overrides = []): LeaveRequest
    {
        return $this->service()->submit($user, array_merge([
            'category' => $category,
            'starts_on' => $start,
            'ends_on' => $end,
            'reason' => self::REASON,
        ], $overrides));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The input was accepted.');
    }

    // --- Working days -----------------------------------------------------------------------------

    public function test_working_days_are_monday_to_friday_without_holidays(): void
    {
        $days = app(WorkingDays::class);

        // Wednesday 4 to Tuesday 10 November: Wed, Thu, Fri, Mon, Tue.
        $this->assertSame(5, $days->count(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10')));

        Holiday::query()->create(['date' => '2026-11-06', 'name' => 'Holiday']);
        Holiday::query()->create(['date' => '2026-11-07', 'name' => 'Saturday holiday']);

        $this->assertSame(4, $days->count(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10')));
        $this->assertSame(0, $days->count(CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-08')));
        $this->assertSame(0, $days->count(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-04')));
    }

    // --- Success ----------------------------------------------------------------------------------

    public function test_an_annual_leave_request_is_pending_with_frozen_days_and_one_allocation(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-13');

        $request->refresh();
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame(5, $request->working_days);
        $this->assertTrue($request->deducts_balance);
        $this->assertSame(self::REASON, $request->reason);

        $allocation = $request->allocations()->sole();
        $period = LeavePeriod::query()->where('user_id', $this->employee->id)->sole();
        $this->assertSame($period->id, $allocation->leave_period_id);
        $this->assertSame(5, $allocation->days);
        $this->assertSame('2026-11-09', $allocation->starts_on->toDateString());

        // `used` changes only on approval (Phase 10).
        $this->assertSame(0, $period->fresh()->used);
    }

    public function test_a_holiday_added_later_does_not_change_the_frozen_days(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-13');

        Holiday::query()->create(['date' => '2026-11-10', 'name' => 'Late holiday']);

        $this->assertSame(5, $request->fresh()->working_days);
        $this->assertSame(5, $request->allocations()->sole()->days);
    }

    public function test_a_non_deducting_category_needs_no_balance_and_has_no_allocation(): void
    {
        $request = $this->submit($this->employee, '2026-11-04', '2026-12-31', 'sick');

        $this->assertFalse($request->deducts_balance);
        $this->assertSame(0, $request->allocations()->count());
        $this->assertGreaterThan(12, $request->working_days);
    }

    public function test_the_deducting_flag_is_frozen_when_the_category_changes_later(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-09', 'other');

        LeaveCategory::query()->where('code', 'other')->update(['deducts_balance' => true]);

        $this->assertFalse($request->fresh()->deducts_balance);
    }

    public function test_today_is_allowed_and_the_reason_is_trimmed(): void
    {
        $request = $this->submit($this->employee, '2026-11-04', '2026-11-04', 'annual', ['reason' => '   '.self::REASON.'  ']);

        $this->assertSame(self::REASON, $request->reason);
    }

    public function test_every_role_with_an_approval_path_can_submit(): void
    {
        $hrAdmin = $this->supervisedMember(RoleName::HrAdmin);
        $this->member(RoleName::HrAdmin);
        $approver = $this->member(RoleName::Approver);

        $this->assertInstanceOf(LeaveRequest::class, $this->submit($hrAdmin, '2026-11-09', '2026-11-09'));
        $this->assertInstanceOf(LeaveRequest::class, $this->submit($approver, '2026-11-09', '2026-11-09'));
    }

    // --- Period split and projection --------------------------------------------------------------

    public function test_a_request_across_the_reset_is_split_and_the_next_period_is_projected(): void
    {
        // Current period 2026-01-15 to 2027-01-14; the next one does not exist yet.
        $user = $this->supervisedMember(RoleName::Employee, ['leave_anchor_date' => '2026-01-15']);

        $request = $this->submit($user, '2027-01-11', '2027-01-19');

        $this->assertSame(7, $request->working_days);
        $parts = $request->allocations()->get();
        $this->assertCount(2, $parts);

        $this->assertSame(['2026-01-15', '2027-01-14', '2027-01-11', '2027-01-14', 4], [
            $parts[0]->period_starts_on->toDateString(), $parts[0]->period_ends_on->toDateString(),
            $parts[0]->starts_on->toDateString(), $parts[0]->ends_on->toDateString(), $parts[0]->days,
        ]);
        $this->assertNotNull($parts[0]->leave_period_id);

        $this->assertSame(['2027-01-15', '2028-01-14', '2027-01-15', '2027-01-19', 3], [
            $parts[1]->period_starts_on->toDateString(), $parts[1]->period_ends_on->toDateString(),
            $parts[1]->starts_on->toDateString(), $parts[1]->ends_on->toDateString(), $parts[1]->days,
        ]);
        $this->assertTrue($parts[1]->isProjected());

        // Projection writes no period: the daily job opens it when it starts.
        $this->assertSame(1, LeavePeriod::query()->where('user_id', $user->id)->count());
    }

    public function test_each_part_is_checked_against_its_own_period(): void
    {
        $user = $this->supervisedMember(RoleName::Employee, ['leave_anchor_date' => '2026-01-15']);
        // Only 3 days left in the current period.
        LeavePeriod::query()->where('user_id', $user->id)->update(['used' => 9]);

        $errors = $this->errorsOf(fn () => $this->submit($user, '2027-01-11', '2027-01-19'));

        $this->assertStringContainsString('4 working days requested, 3 available', $errors['request'][0]);
        $this->assertSame(0, LeaveRequest::query()->count());

        // The same days entirely in the next period fit, because it is projected with 12 days.
        $this->assertSame(3, $this->submit($user, '2027-01-15', '2027-01-19')->working_days);
    }

    public function test_the_end_date_may_not_pass_the_end_of_the_next_period(): void
    {
        $user = $this->supervisedMember(RoleName::Employee, ['leave_anchor_date' => '2026-01-15']);

        $errors = $this->errorsOf(fn () => $this->submit($user, '2028-01-14', '2028-01-17', 'sick'));
        $this->assertStringContainsString('14 Jan 2028', $errors['ends_on'][0]);

        $this->assertSame(1, $this->submit($user, '2028-01-14', '2028-01-14', 'sick')->working_days);
    }

    public function test_after_the_daily_job_opens_the_next_period_it_is_real_and_a_new_one_is_projected(): void
    {
        $user = $this->supervisedMember(RoleName::Employee, ['leave_anchor_date' => '2026-01-15']);
        $this->travelTo(CarbonImmutable::parse('2027-01-15 08:00'));

        $request = $this->submit($user, '2027-01-15', '2027-01-15');

        $allocation = $request->allocations()->sole();
        $this->assertFalse($allocation->isProjected());
        $this->assertSame('2027-01-15', $allocation->period_starts_on->toDateString());
        $this->assertSame('2029-01-14', $this->service()->slots($user)[1]->endsOn->toDateString());
    }

    public function test_pending_requests_reduce_what_is_available(): void
    {
        // 10 working days.
        $this->submit($this->employee, '2026-11-09', '2026-11-20');

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-23', '2026-11-25'));
        $this->assertStringContainsString('3 working days requested, 2 available', $errors['request'][0]);

        $this->assertSame(2, $this->submit($this->employee, '2026-11-23', '2026-11-24')->working_days);

        $slots = $this->service()->slots($this->employee);
        $this->assertSame([12, 0, 12, 0], [$slots[0]->pending, $slots[0]->available(), $slots[1]->available(), $slots[1]->pending]);
    }

    public function test_cancelled_requests_free_their_balance_and_dates(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-20');
        $this->service()->cancel($this->employee, $request->id);

        $this->assertSame(10, $this->submit($this->employee, '2026-11-09', '2026-11-20')->working_days);
    }

    public function test_used_days_reduce_what_is_available(): void
    {
        LeavePeriod::query()->where('user_id', $this->employee->id)->update(['used' => 12]);

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-09', '2026-11-09'));

        $this->assertStringContainsString('1 working days requested, 0 available', $errors['request'][0]);
    }

    public function test_pending_allocations_follow_the_dates_after_a_manual_reset(): void
    {
        $request = $this->submit($this->employee, '2027-01-04', '2027-01-15');
        $this->assertSame(10, $request->working_days);

        $admin = $this->member(RoleName::HrAdmin);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00'));
        app(LeaveBalance::class)->reset($admin, $this->employee, 'Reset agreed with the employee.');

        // The new period starts 2026-12-01 and holds the pending part that starts in January.
        $slots = $this->service()->slots($this->employee);
        $this->assertSame('2026-12-01', $slots[0]->startsOn->toDateString());
        $this->assertSame(10, $slots[0]->pending);
    }

    // --- Validation -------------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'start in the past' => [['starts_on' => '2026-11-03'], 'starts_on'],
            'end before start' => [['starts_on' => '2026-11-10', 'ends_on' => '2026-11-09'], 'ends_on'],
            'wrong date format' => [['starts_on' => '10/11/2026'], 'starts_on'],
            'missing end' => [['ends_on' => ''], 'ends_on'],
            'reason too short' => [['reason' => 'Too short'], 'reason'],
            'reason of spaces' => [['reason' => '            '], 'reason'],
            'reason too long' => [['reason' => str_repeat('a', 1001)], 'reason'],
            'reason not a string' => [['reason' => ['an', 'array']], 'reason'],
            'unknown category' => [['category' => 'holiday'], 'category'],
            'category not a string' => [['category' => 1], 'category'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_refused(array $overrides, string $field): void
    {
        $errors = $this->errorsOf(fn () => $this->service()->submit($this->employee, array_merge([
            'category' => 'annual',
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-10',
            'reason' => self::REASON,
        ], $overrides)));

        $this->assertArrayHasKey($field, $errors);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_an_end_before_the_start_gets_its_own_message(): void
    {
        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-10', '2026-11-09'));

        $this->assertSame(['The end date cannot be before the start date.'], $errors['ends_on']);
    }

    public function test_a_user_without_a_role_has_no_approval_path(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();

        $this->assertSame('Your account has no role that can submit requests.', app(ApprovalPath::class)->problem($nobody->load('roles')));
    }

    public function test_a_user_without_the_permission_cannot_cancel(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-09');
        Role::findByName(RoleName::Employee->value)->revokePermissionTo(PermissionName::RequestsSubmit->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $this->service()->cancel($this->employee->fresh(), $request->id);
            $this->fail('Cancelled without the permission.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    public function test_an_inactive_category_is_refused(): void
    {
        LeaveCategory::query()->where('code', 'other')->update(['is_active' => false]);

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-09', '2026-11-09', 'other'));

        $this->assertSame(['Choose an active leave category.'], $errors['category']);
    }

    public function test_dates_without_a_working_day_are_refused(): void
    {
        Holiday::query()->create(['date' => '2026-11-09', 'name' => 'Holiday']);

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-07', '2026-11-09', 'sick'));

        $this->assertSame(['These dates contain no working day.'], $errors['ends_on']);
    }

    public function test_a_request_over_the_balance_is_refused(): void
    {
        // 13 working days.
        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-04', '2026-11-20'));

        $this->assertStringContainsString('13 working days requested, 12 available', $errors['request'][0]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function overlappingLeave(): array
    {
        return [
            'same dates' => ['2026-11-09', '2026-11-13', 'starts_on'],
            'inside' => ['2026-11-10', '2026-11-10', 'starts_on'],
            'around' => ['2026-11-06', '2026-11-16', 'starts_on'],
            'touching the last day' => ['2026-11-13', '2026-11-16', 'starts_on'],
            'touching the first day' => ['2026-11-05', '2026-11-09', 'starts_on'],
        ];
    }

    #[DataProvider('overlappingLeave')]
    public function test_overlapping_pending_or_approved_leave_is_refused(string $start, string $end, string $field): void
    {
        $this->submit($this->employee, '2026-11-09', '2026-11-13', 'sick');

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, $start, $end, 'sick'));
        $this->assertSame(['These dates overlap another leave request of yours that is pending or approved.'], $errors[$field]);

        LeaveRequest::query()->update(['status' => RequestStatus::Approved->value]);
        $this->assertArrayHasKey($field, $this->errorsOf(fn () => $this->submit($this->employee, $start, $end, 'sick')));
    }

    public function test_rejected_and_cancelled_leave_does_not_block_dates(): void
    {
        $this->submit($this->employee, '2026-11-09', '2026-11-13', 'sick');
        LeaveRequest::query()->update(['status' => RequestStatus::Rejected->value]);

        $this->submit($this->employee, '2026-11-09', '2026-11-13', 'sick');
        LeaveRequest::query()->where('status', 'pending')->update(['status' => RequestStatus::Cancelled->value]);

        $this->assertSame(5, $this->submit($this->employee, '2026-11-09', '2026-11-13', 'sick')->working_days);
    }

    public function test_adjacent_leave_is_allowed(): void
    {
        $this->submit($this->employee, '2026-11-09', '2026-11-10', 'sick');

        $this->assertSame(1, $this->submit($this->employee, '2026-11-11', '2026-11-11', 'sick')->working_days);
    }

    public function test_someone_elses_leave_does_not_block_dates(): void
    {
        $this->submit($this->supervisedMember(), '2026-11-09', '2026-11-13', 'sick');

        $this->assertSame(5, $this->submit($this->employee, '2026-11-09', '2026-11-13', 'sick')->working_days);
    }

    public function test_leave_on_a_date_touched_by_pending_or_approved_overtime_is_refused(): void
    {
        // 22:00 on the 9th to 02:00 on the 10th touches both dates.
        app(OvertimeRequests::class)->submit($this->employee, ['start_at' => '2026-11-09 22:00', 'end_at' => '2026-11-10 02:00', 'reason' => self::REASON]);

        foreach (['2026-11-09', '2026-11-10'] as $date) {
            $errors = $this->errorsOf(fn () => $this->submit($this->employee, $date, $date, 'sick'));
            $this->assertSame(['These dates overlap an overtime request of yours that is pending or approved.'], $errors['starts_on']);
        }

        $this->assertSame(1, $this->submit($this->employee, '2026-11-11', '2026-11-11', 'sick')->working_days);
    }

    public function test_overtime_ending_at_midnight_does_not_touch_the_next_day(): void
    {
        app(OvertimeRequests::class)->submit($this->employee, ['start_at' => '2026-11-09 18:00', 'end_at' => '2026-11-10 00:00', 'reason' => self::REASON]);

        $this->assertSame(1, $this->submit($this->employee, '2026-11-10', '2026-11-10', 'sick')->working_days);
    }

    public function test_messages_follow_the_language(): void
    {
        app()->setLocale('id');

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-03', '2026-11-04'));

        $this->assertSame(['Tanggal mulai tidak boleh sebelum hari ini.'], $errors['starts_on']);
    }

    // --- Approval path and authorization ----------------------------------------------------------

    public function test_an_employee_without_an_active_supervisor_cannot_submit(): void
    {
        $alone = $this->member(RoleName::Employee);
        $errors = $this->errorsOf(fn () => $this->submit($alone, '2026-11-09', '2026-11-09'));
        $this->assertStringContainsString('no active supervisor', $errors['request'][0]);

        $withSuspended = $this->member(RoleName::Employee);
        $this->supervise($this->member(RoleName::Approver, ['status' => UserStatus::Suspended]), $withSuspended);
        $this->assertArrayHasKey('request', $this->errorsOf(fn () => $this->submit($withSuspended, '2026-11-09', '2026-11-09')));

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_an_hr_admin_without_a_supervisor_cannot_submit(): void
    {
        $admin = $this->member(RoleName::HrAdmin);

        $this->assertStringContainsString('no active supervisor', $this->errorsOf(fn () => $this->submit($admin, '2026-11-09', '2026-11-09'))['request'][0]);
    }

    public function test_an_approver_needs_an_active_hr_admin(): void
    {
        $approver = $this->member(RoleName::Approver);

        $errors = $this->errorsOf(fn () => $this->submit($approver, '2026-11-09', '2026-11-09'));
        $this->assertSame(['There is no active HR Admin to decide your requests right now. Contact HR.'], $errors['request']);

        $this->member(RoleName::HrAdmin, ['status' => UserStatus::Suspended]);
        $this->assertArrayHasKey('request', $this->errorsOf(fn () => $this->submit($approver, '2026-11-09', '2026-11-09')));

        $this->member(RoleName::HrAdmin);
        $this->assertInstanceOf(LeaveRequest::class, $this->submit($approver, '2026-11-09', '2026-11-09'));
    }

    public function test_the_path_is_read_from_the_database_not_the_given_instance(): void
    {
        $stale = User::query()->findOrFail($this->employee->id);
        DB::table('supervisor_user')->where('subordinate_id', $this->employee->id)->delete();

        $this->assertArrayHasKey('request', $this->errorsOf(fn () => $this->submit($stale, '2026-11-09', '2026-11-09')));
    }

    public function test_a_suspended_account_cannot_submit_even_with_a_stale_instance(): void
    {
        $stale = User::query()->findOrFail($this->employee->id);
        DB::table('users')->where('id', $this->employee->id)->update(['status' => UserStatus::Suspended->value]);

        $errors = $this->errorsOf(fn () => $this->submit($stale, '2026-11-09', '2026-11-09'));

        $this->assertSame(['Only active accounts can submit requests.'], $errors['request']);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        Role::findByName(RoleName::Employee->value)->revokePermissionTo(PermissionName::RequestsSubmit->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);

        $this->submit($this->employee->fresh(), '2026-11-09', '2026-11-09');
    }

    public function test_submissions_are_throttled_per_user(): void
    {
        foreach (range(1, config('requests.submissions_per_minute')) as $i) {
            $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-03', '2026-11-03'));
        }

        $errors = $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-09', '2026-11-09'));
        $this->assertStringContainsString('Too many requests were submitted', $errors['request'][0]);

        // Another user has their own limit.
        $this->assertInstanceOf(LeaveRequest::class, $this->submit($this->supervisedMember(), '2026-11-09', '2026-11-09'));

        $this->travel(61)->seconds();
        $this->assertInstanceOf(LeaveRequest::class, $this->submit($this->employee, '2026-11-09', '2026-11-09'));
    }

    // --- Cancel -----------------------------------------------------------------------------------

    public function test_the_requester_cancels_a_pending_request_once(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-13');

        $cancelled = $this->service()->cancel($this->employee, (string) $request->id);
        $this->assertSame(RequestStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertNotNull($cancelled->fresh()->cancelled_at);

        $errors = $this->errorsOf(fn () => $this->service()->cancel($this->employee, $request->id));
        $this->assertSame(['This request is no longer pending: it is Cancelled.'], $errors['request']);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::LeaveRequestCancelled->value)->count());
    }

    public function test_a_decided_request_cannot_be_cancelled(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-13');
        LeaveRequest::query()->whereKey($request->id)->update(['status' => RequestStatus::Approved->value]);

        $errors = $this->errorsOf(fn () => $this->service()->cancel($this->employee, $request->id));

        $this->assertSame(['This request is no longer pending: it is Approved.'], $errors['request']);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_someone_elses_request_cannot_be_cancelled_and_is_not_found(): void
    {
        $request = $this->submit($this->employee, '2026-11-09', '2026-11-13');

        try {
            $this->service()->cancel($this->supervisedMember(), $request->id);
            $this->fail('Cancelled another user\'s request.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    public function test_a_forged_id_is_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->service()->cancel($this->employee, '1 OR 1=1');
    }

    // --- Audit ------------------------------------------------------------------------------------

    public function test_creation_is_audited_without_the_reason(): void
    {
        $user = $this->supervisedMember(RoleName::Employee, ['leave_anchor_date' => '2026-01-15']);
        $request = $this->submit($user, '2027-01-11', '2027-01-19');

        $audit = AuditLog::query()->where('event', AuditEvent::LeaveRequestCreated->value)->sole();

        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($request->id, $audit->subject_id);
        $this->assertSame('annual', $audit->metadata['category']);
        $this->assertSame(7, $audit->metadata['working_days']);
        $this->assertEquals([
            ['period_starts_on' => '2026-01-15', 'days' => 4, 'projected' => false],
            ['period_starts_on' => '2027-01-15', 'days' => 3, 'projected' => true],
        ], $audit->metadata['allocations']);
        $this->assertStringNotContainsString('Family', json_encode($audit->metadata));
    }

    public function test_a_refused_request_leaves_nothing_behind(): void
    {
        $this->errorsOf(fn () => $this->submit($this->employee, '2026-11-04', '2026-11-20'));

        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame(0, LeaveRequestAllocation::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::LeaveRequestCreated->value)->count());
    }

    public function test_the_category_seeder_is_idempotent_and_updates_by_code(): void
    {
        $this->seed(LeaveCategorySeeder::class);
        LeaveCategory::query()->where('code', 'sick')->update(['name' => 'Changed']);
        $this->seed(LeaveCategorySeeder::class);

        $this->assertSame(['annual', 'sick', 'other'], LeaveCategory::query()->orderBy('id')->pluck('code')->all());
        $this->assertSame('Sick leave', LeaveCategory::query()->where('code', 'sick')->value('name'));
        $this->assertSame([true, false, false], LeaveCategory::query()->orderBy('id')->pluck('deducts_balance')->all());
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('requests:submit:'.$this->employee->id);

        parent::tearDown();
    }
}
