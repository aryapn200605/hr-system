<?php

namespace Tests\Feature;

use App\Actions\SubmitLeaveRequest;
use App\Actions\SubmitOvertimeRequest;
use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesRequests;
use Tests\TestCase;

class OvertimeRequestTest extends TestCase
{
    use MakesRequests, RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->employee = $this->supervisedEmployee();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function submit(array $input, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->employee)->from(route('requests.overtime.create'))->post(route('requests.overtime.store'), $input);
    }

    private function assertRefused(TestResponse $response, string $field, string $message): void
    {
        $response->assertSessionHasErrors($field);
        $this->assertStringContainsString($message, session('errors')->first($field));
    }

    private function hold(string $start, string $end): OvertimeRequest
    {
        return app(SubmitOvertimeRequest::class)->handle($this->employee, $this->overtimeInput($start, $end), null);
    }

    private function holdLeave(string $start, string $end): void
    {
        app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput($start, $end), null);
    }

    public function test_a_request_is_created_pending_and_audited(): void
    {
        $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T21:00'))->assertRedirect();

        $request = OvertimeRequest::sole();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame(3, $request->hours);
        $this->assertSame('2026-06-15 18:00:00', DB::table('overtime_requests')->value('start_at'));

        $entry = AuditLog::where('event', AuditEvent::OvertimeRequestCreated->value)->sole();

        $this->assertSame(3, $entry->metadata['hours']);
        $this->assertStringNotContainsString(self::VALID_REASON, json_encode($entry->metadata));
    }

    public function test_overtime_can_run_past_midnight(): void
    {
        $this->submit($this->overtimeInput('2026-06-15T22:00', '2026-06-16T04:00'))->assertSessionHasNoErrors();

        $this->assertSame(6, OvertimeRequest::sole()->hours);
    }

    public function test_exactly_twelve_hours_is_allowed_and_thirteen_is_not(): void
    {
        $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-16T06:00'))->assertSessionHasNoErrors();

        $this->assertRefused($this->submit($this->overtimeInput('2026-06-17T08:00', '2026-06-17T21:00')), 'end_at', 'at most 12 hours');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function notOnTheHour(): array
    {
        return [
            'start at :30' => ['2026-06-15T18:30', '2026-06-15T20:00', 'start_at'],
            'end at :15' => ['2026-06-15T18:00', '2026-06-15T20:15', 'end_at'],
            'end at :01' => ['2026-06-15T18:00', '2026-06-15T20:01', 'end_at'],
        ];
    }

    #[DataProvider('notOnTheHour')]
    public function test_minutes_must_be_zero(string $start, string $end, string $field): void
    {
        $this->assertRefused($this->submit($this->overtimeInput($start, $end)), $field, 'on the hour');
    }

    public function test_the_end_must_be_after_the_start(): void
    {
        $this->assertRefused($this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T18:00')), 'end_at', 'after the start');
        $this->assertRefused($this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T17:00')), 'end_at', 'after the start');
    }

    public function test_the_start_cannot_be_before_today_but_can_be_earlier_today(): void
    {
        $this->assertRefused($this->submit($this->overtimeInput('2026-06-14T18:00', '2026-06-14T20:00')), 'start_at', 'before today');
        $this->assertRefused($this->submit($this->overtimeInput('2026-06-14T23:00', '2026-06-15T01:00')), 'start_at', 'before today');

        $this->submit($this->overtimeInput('2026-06-15T01:00', '2026-06-15T02:00'))->assertSessionHasNoErrors();
    }

    public function test_a_reason_is_required_with_its_limits(): void
    {
        foreach (['', '123456789', str_repeat('a', 1001)] as $reason) {
            $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T20:00', ['reason' => $reason]))->assertSessionHasErrors('reason');
        }

        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_bad_formats_are_refused(): void
    {
        $this->submit($this->overtimeInput('2026-06-15 18:00', '2026-06-15T20:00'))->assertSessionHasErrors('start_at');
        $this->submit($this->overtimeInput('2026-06-15T18:00', ''))->assertSessionHasErrors('end_at');
        $this->submit($this->overtimeInput('2026-06-15T18:00:30', '2026-06-15T20:00'))->assertSessionHasErrors('start_at');
    }

    public function test_someone_without_a_way_to_be_decided_is_refused(): void
    {
        $this->assertRefused($this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T20:00'), $this->person(RoleName::Employee)), 'form', 'no active supervisor');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingOvertime(): array
    {
        return [
            'same' => ['2026-06-15T18:00', '2026-06-15T21:00'],
            'starts inside' => ['2026-06-15T20:00', '2026-06-15T22:00'],
            'ends inside' => ['2026-06-15T16:00', '2026-06-15T19:00'],
            'inside' => ['2026-06-15T19:00', '2026-06-15T20:00'],
            'around' => ['2026-06-15T17:00', '2026-06-15T22:00'],
        ];
    }

    #[DataProvider('overlappingOvertime')]
    public function test_overlapping_overtime_is_refused(string $start, string $end): void
    {
        $this->hold('2026-06-15T18:00', '2026-06-15T21:00');

        $this->assertRefused($this->submit($this->overtimeInput($start, $end)), 'start_at', 'overlaps your pending overtime request of 15 Jun 2026, 18:00 to 15 Jun 2026, 21:00');
    }

    public function test_an_end_equal_to_the_next_start_is_fine_both_ways(): void
    {
        $this->hold('2026-06-15T18:00', '2026-06-15T21:00');

        $this->submit($this->overtimeInput('2026-06-15T21:00', '2026-06-15T23:00'))->assertSessionHasNoErrors();
        $this->submit($this->overtimeInput('2026-06-15T16:00', '2026-06-15T18:00'))->assertSessionHasNoErrors();
    }

    public function test_cancelled_overtime_frees_its_hours(): void
    {
        $this->hold('2026-06-15T18:00', '2026-06-15T21:00')->forceFill(['status' => RequestStatus::Cancelled])->save();

        $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T21:00'))->assertSessionHasNoErrors();
    }

    public function test_another_users_overtime_does_not_collide(): void
    {
        $this->hold('2026-06-15T18:00', '2026-06-15T21:00');

        $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T21:00'), $this->supervisedEmployee())->assertSessionHasNoErrors();
    }

    public function test_overtime_on_a_leave_date_is_refused(): void
    {
        $this->holdLeave('2026-06-16', '2026-06-17');

        $this->assertRefused($this->submit($this->overtimeInput('2026-06-16T18:00', '2026-06-16T20:00')), 'start_at', 'overlaps your pending leave request of 16 Jun 2026 to 17 Jun 2026');
    }

    public function test_overtime_crossing_midnight_into_a_leave_date_is_refused(): void
    {
        $this->holdLeave('2026-06-16', '2026-06-16');

        $this->assertRefused($this->submit($this->overtimeInput('2026-06-15T22:00', '2026-06-16T01:00')), 'start_at', 'overlaps');
    }

    public function test_overtime_crossing_midnight_out_of_a_leave_date_is_refused(): void
    {
        $this->holdLeave('2026-06-16', '2026-06-16');

        $this->assertRefused($this->submit($this->overtimeInput('2026-06-16T22:00', '2026-06-17T01:00')), 'start_at', 'overlaps');
    }

    public function test_overtime_ending_at_midnight_does_not_touch_the_next_date(): void
    {
        $this->holdLeave('2026-06-16', '2026-06-16');

        $this->submit($this->overtimeInput('2026-06-15T20:00', '2026-06-16T00:00'))->assertSessionHasNoErrors();
    }

    public function test_overtime_starting_at_midnight_after_leave_is_fine(): void
    {
        $this->holdLeave('2026-06-16', '2026-06-16');

        $this->submit($this->overtimeInput('2026-06-17T00:00', '2026-06-17T02:00'))->assertSessionHasNoErrors();
    }

    public function test_overtime_is_allowed_on_a_weekend(): void
    {
        $this->submit($this->overtimeInput('2026-06-20T09:00', '2026-06-20T12:00'))->assertSessionHasNoErrors();
    }

    public function test_status_and_owner_cannot_arrive_with_the_form(): void
    {
        $other = $this->person(RoleName::HrAdmin);

        $this->submit($this->overtimeInput('2026-06-15T18:00', '2026-06-15T20:00', ['status' => 'approved', 'user_id' => $other->id, 'hours' => 1]))
            ->assertSessionHasNoErrors();

        $request = OvertimeRequest::sole();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertSame(2, $request->hours);
    }
}
