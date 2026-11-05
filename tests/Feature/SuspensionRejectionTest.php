<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Exceptions\SupervisorGuardException;
use App\Livewire\Approvals\Show as ApprovalShow;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Requests\Show as RequestShow;
use App\Livewire\Users\Index as UserIndex;
use App\Models\AuditLog;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\ReviewLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

/**
 * Phase 14 decisions (DECISIONS.md section 11): pending requests are rejected by the system when their
 * requester is suspended, decisions are final, and sick leave cannot be backdated. The real-process race
 * of suspension against approval is in ApprovalConcurrencyTest.
 */
class SuspensionRejectionTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Family matters out of town.';

    private User $admin;

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->admin = $this->member(RoleName::HrAdmin, ['name' => 'Hana Admin']);
        $this->supervisor = $this->member(RoleName::Approver, ['name' => 'Siti Supervisor']);
        $this->employee = $this->member(RoleName::Employee, ['name' => 'Eko Employee']);
        $this->supervise($this->supervisor, $this->employee);
    }

    private function leave(User $user, string $start = '2026-11-09', string $end = '2026-11-10', string $category = 'annual'): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, ['category' => $category, 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON]);
    }

    private function overtime(User $user, string $start = '2026-11-05 17:00', string $end = '2026-11-05 20:00'): OvertimeRequest
    {
        return app(OvertimeRequests::class)->submit($user, ['start_at' => $start, 'end_at' => $end, 'reason' => self::REASON]);
    }

    private function period(User $user): LeavePeriod
    {
        return LeavePeriod::query()->where('user_id', $user->id)->sole();
    }

    // --- Automatic rejection ----------------------------------------------------------------------

    public function test_suspending_rejects_every_pending_request_and_leaves_decided_ones_alone(): void
    {
        $pendingLeave = $this->leave($this->employee);
        $pendingSick = $this->leave($this->employee, '2026-11-16', '2026-11-16', 'sick');
        $pendingOvertime = $this->overtime($this->employee);
        $approved = $this->leave($this->employee, '2026-11-23', '2026-11-24');
        app(Decisions::class)->approve($this->supervisor, 'leave', $approved->id);
        $rejected = $this->overtime($this->employee, '2026-11-06 17:00', '2026-11-06 19:00');
        app(Decisions::class)->reject($this->supervisor, 'overtime', $rejected->id, 'Not needed this week.');
        $cancelled = $this->overtime($this->employee, '2026-11-12 17:00', '2026-11-12 19:00');
        app(OvertimeRequests::class)->cancel($this->employee, $cancelled->id);
        $other = $this->supervisedMember();
        $othersRequest = $this->leave($other);
        $before = [$approved->fresh()->toArray(), $rejected->fresh()->toArray(), $cancelled->fresh()->toArray()];

        $this->travel(5)->minutes();
        $result = $this->employee->suspend($this->admin);

        $this->assertSame(['leave' => 2, 'overtime' => 1], $result);

        foreach ([$pendingLeave, $pendingSick, $pendingOvertime] as $record) {
            $record->refresh();
            $this->assertSame(RequestStatus::Rejected, $record->status);
            $this->assertNull($record->decided_by, 'The system decides: no decider.');
            $this->assertTrue($record->decided_at->equalTo(now()));
            $this->assertNull($record->decision_note);
            $this->assertSame('account_suspended', $record->system_reason);
            $this->assertTrue($record->decidedBySystem());
        }

        // Decided and cancelled requests, and other users' requests, are untouched.
        $this->assertSame($before, [$approved->fresh()->toArray(), $rejected->fresh()->toArray(), $cancelled->fresh()->toArray()]);
        $this->assertSame(RequestStatus::Pending, $othersRequest->fresh()->status);
    }

    public function test_the_pending_allocation_is_released_and_used_days_do_not_change(): void
    {
        $approved = $this->leave($this->employee, '2026-11-23', '2026-11-24');
        app(Decisions::class)->approve($this->supervisor, 'leave', $approved->id);
        // Ten days pending: with two used, nothing else would fit.
        $pending = $this->leave($this->employee, '2026-11-09', '2026-11-20');
        $this->assertSame(10, $pending->working_days);
        $usedBefore = $this->period($this->employee)->used;
        $entriesBefore = DB::table('leave_balance_entries')->where('user_id', $this->employee->id)->count();

        $this->employee->suspend($this->admin);

        $this->assertSame($usedBefore, $this->period($this->employee)->used);
        $this->assertSame(2, $usedBefore);
        $this->assertSame($entriesBefore, DB::table('leave_balance_entries')->where('user_id', $this->employee->id)->count(), 'No ledger entry.');

        // Reactivated, the user has the ten days back to request, and the old request stays rejected.
        $this->employee->reactivate($this->admin);
        $this->assertSame(RequestStatus::Rejected, $pending->fresh()->status, 'Reactivation restores nothing.');
        $again = $this->leave($this->employee->fresh(), '2026-11-09', '2026-11-20');
        $this->assertSame(RequestStatus::Pending, $again->status);
    }

    public function test_the_audit_trail_holds_the_counts_and_one_row_per_request(): void
    {
        $leave = $this->leave($this->employee);
        $overtime = $this->overtime($this->employee);

        $this->employee->suspend($this->admin);

        $suspended = AuditLog::query()->where('event', AuditEvent::UserSuspended->value)->sole();
        $this->assertSame(1, $suspended->metadata['leave_requests_rejected']);
        $this->assertSame(1, $suspended->metadata['overtime_requests_rejected']);
        $this->assertSame($this->admin->id, $suspended->actor_id);

        foreach ([[AuditEvent::LeaveRequestRejected, $leave], [AuditEvent::OvertimeRequestRejected, $overtime]] as [$event, $record]) {
            $row = AuditLog::query()->where('event', $event->value)->where('subject_id', $record->id)->sole();
            $this->assertSame('account_suspended', $row->metadata['system_reason']);
            $this->assertSame($this->employee->id, $row->metadata['requester_id']);
            $this->assertArrayNotHasKey('reason', $row->metadata);
        }
    }

    public function test_suspending_without_pending_requests_writes_zero_counts(): void
    {
        $this->assertSame(['leave' => 0, 'overtime' => 0], $this->employee->suspend($this->admin));

        $metadata = AuditLog::query()->where('event', AuditEvent::UserSuspended->value)->sole()->metadata;
        $this->assertSame([0, 0], [$metadata['leave_requests_rejected'], $metadata['overtime_requests_rejected']]);
    }

    public function test_no_email_is_sent_for_an_automatic_rejection(): void
    {
        $this->leave($this->employee);
        $this->overtime($this->employee);
        Mail::fake();

        $this->employee->suspend($this->admin);

        Mail::assertNothingOutgoing();
    }

    public function test_a_refused_suspension_rejects_nothing(): void
    {
        // The supervisor is the employee's only one: the guard refuses, and the supervisor's own request stays.
        $request = $this->overtime($this->supervisor);

        try {
            $this->supervisor->suspend($this->admin);
            $this->fail('The guard let the only supervisor go.');
        } catch (SupervisorGuardException) {
        }

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(UserStatus::Active, $this->supervisor->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::OvertimeRequestRejected->value)->count());
    }

    public function test_a_second_suspension_is_refused_and_writes_nothing(): void
    {
        $this->leave($this->employee);
        $this->employee->suspend($this->admin);
        $rows = AuditLog::query()->count();

        $this->expectException(AccountProtectionException::class);

        try {
            User::query()->findOrFail($this->employee->id)->suspend($this->admin);
        } finally {
            $this->assertSame($rows, AuditLog::query()->count());
        }
    }

    public function test_an_approver_and_an_hr_admin_lose_their_own_pending_requests_but_not_those_waiting_for_them(): void
    {
        // A second supervisor so the guard lets the first one go.
        $this->supervise($this->member(RoleName::Approver), $this->employee);
        $waiting = $this->leave($this->employee);
        $own = $this->overtime($this->supervisor);
        $adminRequester = $this->supervisedMember(RoleName::HrAdmin);
        $adminsOwn = $this->overtime($adminRequester);

        $this->supervisor->suspend($this->admin);
        $adminRequester->suspend($this->admin);

        $this->assertSame(RequestStatus::Rejected, $own->fresh()->status);
        $this->assertSame(RequestStatus::Rejected, $adminsOwn->fresh()->status);
        $this->assertSame(RequestStatus::Pending, $waiting->fresh()->status, 'Subordinates\' requests are not part of this decision.');
    }

    public function test_a_suspended_user_still_cannot_submit(): void
    {
        $this->employee->suspend($this->admin);
        $stale = $this->employee;

        foreach ([fn () => $this->leave($stale, '2026-11-09', '2026-11-09', 'sick'), fn () => $this->overtime($stale)] as $submit) {
            try {
                $submit();
                $this->fail('A suspended user submitted a request.');
            } catch (AuthorizationException|ValidationException) {
            }
        }

        $this->assertSame(0, LeaveRequest::query()->where('user_id', $this->employee->id)->count());
        $this->assertSame(0, OvertimeRequest::query()->where('user_id', $this->employee->id)->count());
    }

    public function test_a_soft_lock_held_on_a_pending_request_is_cleared(): void
    {
        $request = $this->leave($this->employee);
        app(ReviewLock::class)->acquire($this->supervisor, LeaveRequest::class, $request->id);
        $this->assertSame($this->supervisor->id, $request->fresh()->review_locked_by);

        $this->employee->suspend($this->admin);

        $request->refresh();
        $this->assertNull($request->review_locked_by);
        $this->assertNull($request->review_locked_until);
    }

    public function test_a_decider_then_sees_already_decided_by_the_system(): void
    {
        $request = $this->leave($this->employee);
        $this->employee->suspend($this->admin);

        try {
            app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);
            $this->fail('A rejected request was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already decided: Rejected by System', $exception->errors()['decision'][0]);
        }

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame(0, $this->period($this->employee)->used);
    }

    // --- How it is shown --------------------------------------------------------------------------

    public function test_the_fixed_reason_is_shown_in_the_viewers_language(): void
    {
        $request = $this->leave($this->employee);
        $this->employee->suspend($this->admin);
        $request->refresh();

        $this->assertSame('System', $request->deciderLabel());
        $this->assertSame('Rejected automatically because the account was suspended.', $request->decisionText());

        app()->setLocale('id');
        $this->assertSame('Sistem', $request->deciderLabel());
        $this->assertSame('Ditolak otomatis karena akun ditangguhkan.', $request->decisionText());
    }

    public function test_the_requester_page_and_the_approval_page_show_the_system_decision(): void
    {
        $request = $this->leave($this->employee);
        $this->employee->suspend($this->admin);

        Livewire::actingAs($this->supervisor)->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSee('Rejected automatically because the account was suspended.')
            ->assertSeeHtml('<span data-decided-by>System</span>')
            ->assertSee('already decided: Rejected by System')
            ->assertDontSeeHtml('data-approve');

        // The requester, back again, sees it on their own page.
        $this->employee->reactivate($this->admin);
        Livewire::actingAs($this->employee->fresh())->test(RequestShow::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSee('Rejected automatically because the account was suspended.')
            ->assertSeeHtml('<span data-decided-by>System</span>');
    }

    public function test_history_and_its_export_name_the_system_as_decider(): void
    {
        $this->leave($this->employee);
        $this->employee->suspend($this->admin);

        Livewire::actingAs($this->admin)->test(HistoryIndex::class)->assertSee('by System');

        $csv = $this->actingAs($this->admin)->get(route('history.export'))->streamedContent();
        $this->assertStringContainsString(';Rejected;', $csv);
        $this->assertStringContainsString(';System;', $csv);
    }

    // --- The suspension dialog --------------------------------------------------------------------

    public function test_the_suspension_dialog_names_the_number_of_pending_requests(): void
    {
        $this->leave($this->employee);
        $this->overtime($this->employee);
        $other = $this->member(RoleName::Employee);

        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->call('openSuspend', $this->employee->id)
            ->assertSet('pendingRequests', 2)
            ->assertSee('2 pending requests of this user (leave and overtime) will be rejected automatically.')
            ->call('confirm')
            ->assertDispatched('toast', message: 'Eko Employee was suspended and signed out. 2 pending requests were rejected.')
            ->call('openSuspend', $other->id)
            ->assertSet('pendingRequests', 0)
            ->assertSee('This user has no pending requests.');
    }

    public function test_the_dialog_count_cannot_be_forged(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->call('openSuspend', $this->employee->id)
            ->set('pendingRequests', 99);
    }

    // --- Final decisions --------------------------------------------------------------------------

    public function test_both_decision_dialogs_say_the_decision_is_final(): void
    {
        $request = $this->leave($this->employee);

        $html = Livewire::actingAs($this->supervisor)->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $request->id])->html();

        $this->assertSame(2, substr_count($html, 'This decision is final. It cannot be undone or changed later.'));
    }

    public function test_a_decided_request_cannot_be_cancelled_or_decided_again(): void
    {
        $approved = $this->leave($this->employee);
        app(Decisions::class)->approve($this->supervisor, 'leave', $approved->id);
        $rejected = $this->overtime($this->employee);
        app(Decisions::class)->reject($this->supervisor, 'overtime', $rejected->id, 'Not needed this week.');
        $second = $this->member(RoleName::Approver);
        $this->supervise($second, $this->employee);

        foreach ([
            fn () => app(LeaveRequests::class)->cancel($this->employee, $approved->id),
            fn () => app(OvertimeRequests::class)->cancel($this->employee, $rejected->id),
            fn () => app(Decisions::class)->reject($second, 'leave', $approved->id, 'Changed my mind.'),
            fn () => app(Decisions::class)->approve($this->supervisor, 'overtime', $rejected->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A final decision was changed.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(RequestStatus::Approved, $approved->fresh()->status);
        $this->assertSame(RequestStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(2, $this->period($this->employee)->used);
    }

    // --- Sick leave is not backdated --------------------------------------------------------------

    public function test_sick_leave_cannot_start_before_today(): void
    {
        try {
            $this->leave($this->employee, '2026-11-03', '2026-11-04', 'sick');
            $this->fail('A backdated sick leave was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_on', $exception->errors());
        }

        $this->assertSame(RequestStatus::Pending, $this->leave($this->employee, '2026-11-04', '2026-11-04', 'sick')->status, 'Today is allowed.');
    }
}
