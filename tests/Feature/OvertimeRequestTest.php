<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class OvertimeRequestTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Month-end closing of the books.';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:17:42'));
        $this->employee = $this->supervisedMember();
    }

    private function service(): OvertimeRequests
    {
        return app(OvertimeRequests::class);
    }

    private function submit(string $start, string $end, ?User $user = null, string $reason = self::REASON): OvertimeRequest
    {
        return $this->service()->submit($user ?? $this->employee, ['start_at' => $start, 'end_at' => $end, 'reason' => $reason]);
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

    public function test_an_overtime_request_across_midnight_is_stored_in_whole_hours(): void
    {
        $request = $this->submit('2026-11-04 20:00', '2026-11-05 03:00')->fresh();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame(7, $request->hours);
        $this->assertSame('2026-11-04 20:00:00', $request->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 03:00:00', $request->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(['2026-11-04', '2026-11-05'], [$request->first_date->toDateString(), $request->last_date->toDateString()]);
        // Stored as written, in WIB.
        $this->assertSame('2026-11-04 20:00:00', DB::table('overtime_requests')->value('start_at'));
    }

    public function test_an_overtime_ending_at_midnight_touches_one_date(): void
    {
        $request = $this->submit('2026-11-04 18:00', '2026-11-05 00:00');

        $this->assertSame(['2026-11-04', '2026-11-04'], [$request->first_date->toDateString(), $request->last_date->toDateString()]);
    }

    public function test_twelve_hours_and_an_earlier_hour_of_today_are_allowed(): void
    {
        $this->assertSame(12, $this->submit('2026-11-04 06:00', '2026-11-04 18:00')->hours);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidTimes(): array
    {
        return [
            'minutes in the start' => ['2026-11-04 18:30', '2026-11-04 20:00', 'start_at', 'Overtime must start and end on a full hour.'],
            'minutes in the end' => ['2026-11-04 18:00', '2026-11-04 20:15', 'end_at', 'Overtime must start and end on a full hour.'],
            'end equal to start' => ['2026-11-04 18:00', '2026-11-04 18:00', 'end_at', 'The end must be after the start.'],
            'end before start' => ['2026-11-04 18:00', '2026-11-04 17:00', 'end_at', 'The end must be after the start.'],
            'thirteen hours' => ['2026-11-04 18:00', '2026-11-05 07:00', 'end_at', 'Overtime can be at most 12 hours per request.'],
            'yesterday' => ['2026-11-03 22:00', '2026-11-04 01:00', 'start_at', 'The start date cannot be before today.'],
            'more than a year ahead' => ['2027-11-05 18:00', '2027-11-05 20:00', 'start_at', 'Overtime can start at most one year ahead.'],
            'seconds' => ['2026-11-04 18:00:00', '2026-11-04 20:00', 'start_at', ''],
            'not a date' => ['tomorrow', '2026-11-04 20:00', 'start_at', ''],
        ];
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_times_are_refused(string $start, string $end, string $field, string $message): void
    {
        $errors = $this->errorsOf(fn () => $this->submit($start, $end));

        $this->assertArrayHasKey($field, $errors);
        if ($message !== '') {
            $this->assertSame([$message], $errors[$field]);
        }
        $this->assertSame(0, OvertimeRequest::query()->count());
    }

    public function test_one_year_ahead_is_the_last_allowed_day(): void
    {
        $this->assertSame(2, $this->submit('2027-11-04 18:00', '2027-11-04 20:00')->hours);
    }

    public function test_the_reason_is_required(): void
    {
        $this->assertArrayHasKey('reason', $this->errorsOf(fn () => $this->submit('2026-11-04 18:00', '2026-11-04 20:00', reason: 'short')));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function neighbours(): array
    {
        return [
            'ends where the other begins' => ['2026-11-04 15:00', '2026-11-04 18:00', true],
            'begins where the other ends' => ['2026-11-04 21:00', '2026-11-04 23:00', true],
            'one hour overlapping the start' => ['2026-11-04 16:00', '2026-11-04 19:00', false],
            'one hour overlapping the end' => ['2026-11-04 20:00', '2026-11-04 22:00', false],
            'inside' => ['2026-11-04 19:00', '2026-11-04 20:00', false],
            'around' => ['2026-11-04 17:00', '2026-11-04 22:00', false],
        ];
    }

    #[DataProvider('neighbours')]
    public function test_only_hours_that_really_overlap_are_refused(string $start, string $end, bool $allowed): void
    {
        $this->submit('2026-11-04 18:00', '2026-11-04 21:00');

        if ($allowed) {
            $this->assertInstanceOf(OvertimeRequest::class, $this->submit($start, $end));

            return;
        }

        $errors = $this->errorsOf(fn () => $this->submit($start, $end));
        $this->assertSame(['These hours overlap another overtime request of yours that is pending or approved.'], $errors['start_at']);

        OvertimeRequest::query()->update(['status' => RequestStatus::Approved->value]);
        $this->assertArrayHasKey('start_at', $this->errorsOf(fn () => $this->submit($start, $end)));
    }

    public function test_rejected_cancelled_and_other_peoples_overtime_does_not_block(): void
    {
        $this->submit('2026-11-04 18:00', '2026-11-04 21:00');
        OvertimeRequest::query()->update(['status' => RequestStatus::Rejected->value]);
        $this->submit('2026-11-04 18:00', '2026-11-04 21:00');
        OvertimeRequest::query()->where('status', 'pending')->update(['status' => RequestStatus::Cancelled->value]);
        $this->submit('2026-11-04 18:00', '2026-11-04 21:00', $this->supervisedMember());

        $this->assertInstanceOf(OvertimeRequest::class, $this->submit('2026-11-04 18:00', '2026-11-04 21:00'));
    }

    public function test_overtime_touching_a_leave_date_is_refused(): void
    {
        app(LeaveRequests::class)->submit($this->employee, ['category' => 'sick', 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06', 'reason' => self::REASON]);

        // Starts the day before and runs into the leave day.
        $errors = $this->errorsOf(fn () => $this->submit('2026-11-05 22:00', '2026-11-06 01:00'));
        $this->assertSame(['This overtime falls on a date of your leave request that is pending or approved.'], $errors['start_at']);

        // Ends exactly at midnight before the leave day: it does not touch it.
        $this->assertInstanceOf(OvertimeRequest::class, $this->submit('2026-11-05 18:00', '2026-11-06 00:00'));

        LeaveRequest::query()->update(['status' => RequestStatus::Cancelled->value]);
        $this->assertInstanceOf(OvertimeRequest::class, $this->submit('2026-11-06 18:00', '2026-11-06 20:00'));
    }

    public function test_overtime_on_an_approved_leave_date_is_refused(): void
    {
        app(LeaveRequests::class)->submit($this->employee, ['category' => 'annual', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-10', 'reason' => self::REASON]);
        LeaveRequest::query()->update(['status' => RequestStatus::Approved->value]);

        $this->assertArrayHasKey('start_at', $this->errorsOf(fn () => $this->submit('2026-11-10 18:00', '2026-11-10 20:00')));
    }

    public function test_an_employee_without_a_supervisor_and_an_approver_without_hr_admin_are_refused(): void
    {
        $this->assertArrayHasKey('request', $this->errorsOf(fn () => $this->submit('2026-11-04 18:00', '2026-11-04 20:00', $this->member(RoleName::Employee))));

        $approver = $this->member(RoleName::Approver);
        $this->assertArrayHasKey('request', $this->errorsOf(fn () => $this->submit('2026-11-04 18:00', '2026-11-04 20:00', $approver)));

        $this->member(RoleName::HrAdmin);
        $this->assertSame(2, $this->submit('2026-11-04 18:00', '2026-11-04 20:00', $approver)->hours);
    }

    public function test_cancel_is_for_the_requester_and_only_once(): void
    {
        $request = $this->submit('2026-11-04 18:00', '2026-11-04 20:00');

        try {
            $this->service()->cancel($this->supervisedMember(), $request->id);
            $this->fail('Cancelled another user\'s request.');
        } catch (ModelNotFoundException) {
        }

        $this->service()->cancel($this->employee, $request->id);
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);

        $errors = $this->errorsOf(fn () => $this->service()->cancel($this->employee, $request->id));
        $this->assertSame(['This request is no longer pending: it is Cancelled.'], $errors['request']);
    }

    public function test_creation_and_cancellation_are_audited_without_the_reason(): void
    {
        $request = $this->submit('2026-11-04 20:00', '2026-11-05 03:00');
        $this->service()->cancel($this->employee, $request->id);

        $created = AuditLog::query()->where('event', AuditEvent::OvertimeRequestCreated->value)->sole();
        $this->assertEquals(['start_at' => '2026-11-04 20:00', 'end_at' => '2026-11-05 03:00', 'hours' => 7], $created->metadata);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::OvertimeRequestCancelled->value)->count());
        $this->assertStringNotContainsString('closing', AuditLog::query()->pluck('metadata')->toJson());
    }
}
