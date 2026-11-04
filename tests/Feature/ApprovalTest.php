<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Deciders;
use App\Services\Requests\Decisions;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\ReviewLock;
use App\Services\Supervisors;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

/**
 * Who may decide, approving and rejecting, the answer for a request already decided, the soft lock,
 * and the audit trail. Balance deduction is in LeaveApprovalBalanceTest, races in ApprovalConcurrencyTest.
 */
class ApprovalTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Family matters out of town.';

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->supervisor = $this->member(RoleName::Approver, ['name' => 'Siti Supervisor']);
        $this->employee = $this->member(RoleName::Employee);
        $this->supervise($this->supervisor, $this->employee);
    }

    private function decisions(): Decisions
    {
        return app(Decisions::class);
    }

    private function overtime(User $user, string $start = '2026-11-05 17:00', string $end = '2026-11-05 20:00'): OvertimeRequest
    {
        return app(OvertimeRequests::class)->submit($user, ['start_at' => $start, 'end_at' => $end, 'reason' => self::REASON]);
    }

    private function leave(User $user, string $start = '2026-11-09', string $end = '2026-11-10', string $category = 'annual'): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, ['category' => $category, 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The decision was accepted.');
    }

    private function refusedFor(User $decider, LeaveRequest|OvertimeRequest $request): void
    {
        try {
            $this->decisions()->approve($decider, $request->routeType(), $request->id);
            $this->fail('A decider without authority was accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot decide this request.', $exception->getMessage());
        }

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    // --- Who may decide ---------------------------------------------------------------------------

    public function test_a_linked_active_approver_decides_an_employee_request(): void
    {
        $deciders = app(Deciders::class);
        $unlinked = $this->member(RoleName::Approver);
        $admin = $this->member(RoleName::HrAdmin);

        $this->assertTrue($deciders->allows($this->supervisor, $this->employee));
        $this->assertFalse($deciders->allows($unlinked, $this->employee));
        $this->assertFalse($deciders->allows($admin, $this->employee), 'HR Admin does not decide Employees without a link.');
        $this->assertFalse($deciders->allows($this->employee, $this->employee));
    }

    public function test_any_active_hr_admin_decides_an_approver_request(): void
    {
        $deciders = app(Deciders::class);
        $approver = $this->member(RoleName::Approver);
        // Even an Approver who supervises another Approver does not decide their requests.
        $this->supervise($this->supervisor, $approver);

        $this->assertTrue($deciders->allows($this->member(RoleName::HrAdmin), $approver));
        $this->assertFalse($deciders->allows($this->supervisor, $approver));
        $this->assertFalse($deciders->allows($approver, $approver));
        $this->assertFalse($deciders->allows($this->member(RoleName::HrAdmin, ['status' => UserStatus::Suspended]), $approver));
    }

    public function test_an_hr_admin_request_goes_to_their_assigned_supervisor_only(): void
    {
        $deciders = app(Deciders::class);
        $admin = $this->member(RoleName::HrAdmin);
        $this->supervise($this->supervisor, $admin);

        $this->assertTrue($deciders->allows($this->supervisor, $admin));
        $this->assertFalse($deciders->allows($this->member(RoleName::HrAdmin), $admin));
        $this->assertFalse($deciders->allows($this->member(RoleName::Approver), $admin));
        $this->assertFalse($deciders->allows($admin, $admin));
    }

    public function test_a_suspended_supervisor_does_not_decide(): void
    {
        // Straight to the row: the supervisor guard would refuse leaving the employee without one.
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Suspended->value]);

        $this->assertFalse(app(Deciders::class)->allows($this->supervisor->fresh(), $this->employee));
    }

    public function test_a_user_without_a_role_has_nobody_to_decide_for(): void
    {
        $roleless = User::factory()->twoFactorEnrolled()->create();

        $this->assertFalse(app(Deciders::class)->allows($this->supervisor, $roleless));
        $this->assertFalse(app(Deciders::class)->allows($this->member(RoleName::HrAdmin), $roleless));
    }

    public function test_the_inbox_query_matches_the_rule(): void
    {
        $deciders = app(Deciders::class);
        $other = $this->member(RoleName::Employee);
        $approver = $this->member(RoleName::Approver);
        $admin = $this->member(RoleName::HrAdmin);
        $this->supervise($this->supervisor, $admin);
        // An Approver subordinate goes to HR Admin, not to their supervisor.
        $this->supervise($this->supervisor, $approver);

        $this->assertEqualsCanonicalizing([$this->employee->id, $admin->id], $deciders->requesterIds($this->supervisor)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $approver->id], $deciders->requesterIds($admin)->pluck('id')->all());
        $this->assertSame([], $deciders->requesterIds($other)->pluck('id')->all());

        // A suspended supervisor keeps the links but decides nothing.
        DB::table('users')->where('id', $this->supervisor->id)->update(['status' => UserStatus::Suspended->value]);
        $this->assertSame([], $deciders->requesterIds($this->supervisor->fresh())->pluck('id')->all());
    }

    public function test_the_permission_decides_not_the_role_name(): void
    {
        $request = $this->overtime($this->employee);
        Role::findByName(RoleName::Approver->value)->revokePermissionTo(PermissionName::RequestsDecide->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $supervisor = User::query()->findOrFail($this->supervisor->id);

        $this->assertFalse(app(Deciders::class)->allows($supervisor, $this->employee));
        $this->assertSame([], app(Deciders::class)->requesterIds($supervisor)->pluck('id')->all());
        $this->refusedFor($supervisor, $request);
    }

    // --- Approve and reject -----------------------------------------------------------------------

    public function test_approving_overtime_records_who_when_and_the_note(): void
    {
        $request = $this->overtime($this->employee);
        $this->travel(5)->minutes();

        $decided = $this->decisions()->approve($this->supervisor, 'overtime', $request->id, '  Thanks for staying.  ');

        $this->assertSame(RequestStatus::Approved, $decided->status);
        $request->refresh();
        $this->assertSame(RequestStatus::Approved, $request->status);
        $this->assertSame($this->supervisor->id, $request->decided_by);
        $this->assertSame('2026-11-04 10:05:00', $request->decided_at->toDateTimeString());
        $this->assertSame('Thanks for staying.', $request->decision_note);

        $audit = AuditLog::query()->where('event', AuditEvent::OvertimeRequestApproved->value)->sole();
        $this->assertSame($this->supervisor->id, $audit->actor_id);
        $this->assertSame($request->id, $audit->subject_id);
        $this->assertEquals(['requester_id' => $this->employee->id, 'note_given' => true], $audit->metadata);
    }

    public function test_the_approval_note_is_optional_and_limited(): void
    {
        $request = $this->overtime($this->employee);

        $this->assertSame(['note'], array_keys($this->errorsOf(fn () => $this->decisions()->approve($this->supervisor, 'overtime', $request->id, str_repeat('a', 1001)))));
        $this->assertSame(['note'], array_keys($this->errorsOf(fn () => $this->decisions()->approve($this->supervisor, 'overtime', $request->id, ['an', 'array']))));

        $this->decisions()->approve($this->supervisor, 'overtime', $request->id, '   ');
        $this->assertNull($request->fresh()->decision_note);
        $this->assertFalse(AuditLog::query()->where('event', AuditEvent::OvertimeRequestApproved->value)->sole()->metadata['note_given']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badRejectionReasons(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'spaces' => ['        '],
            'four characters' => ['Nope'],
            'four after trimming' => ['  Nope  '],
            'too long' => [str_repeat('a', 1001)],
            'not a string' => [['a', 'list']],
        ];
    }

    #[DataProvider('badRejectionReasons')]
    public function test_a_rejection_needs_a_reason_of_at_least_five_characters(mixed $reason): void
    {
        $request = $this->overtime($this->employee);

        $this->assertSame(['reason'], array_keys($this->errorsOf(fn () => $this->decisions()->reject($this->supervisor, 'overtime', $request->id, $reason))));
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    public function test_rejecting_with_a_five_character_reason(): void
    {
        $request = $this->leave($this->employee);

        $this->decisions()->reject($this->supervisor, 'leave', $request->id, ' Busy. ');

        $request->refresh();
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame('Busy.', $request->decision_note);
        $this->assertSame($this->supervisor->id, $request->decided_by);

        // The audit row never holds the reason text.
        $audit = AuditLog::query()->where('event', AuditEvent::LeaveRequestRejected->value)->sole();
        $this->assertEquals(['requester_id' => $this->employee->id, 'note_given' => true], $audit->metadata);
    }

    public function test_rejection_messages_follow_the_language(): void
    {
        $request = $this->overtime($this->employee);
        app()->setLocale('id');

        $errors = $this->errorsOf(fn () => $this->decisions()->reject($this->supervisor, 'overtime', $request->id, 'Hmm'));

        $this->assertStringContainsStringIgnoringCase('alasan', $errors['reason'][0]);
    }

    public function test_every_requester_role_is_decided_by_the_right_person(): void
    {
        $approver = $this->member(RoleName::Approver);
        $admin = $this->member(RoleName::HrAdmin);
        $this->supervise($this->supervisor, $admin);

        $fromApprover = $this->overtime($approver);
        $fromAdmin = $this->overtime($admin);

        $this->refusedFor($this->supervisor, $fromApprover);
        $this->decisions()->approve($admin, 'overtime', $fromApprover->id);
        $this->assertSame(RequestStatus::Approved, $fromApprover->fresh()->status);

        $this->refusedFor($this->member(RoleName::HrAdmin), $fromAdmin);
        $this->decisions()->approve($this->supervisor, 'overtime', $fromAdmin->id);
        $this->assertSame(RequestStatus::Approved, $fromAdmin->fresh()->status);
    }

    // --- Authority at the moment of the action ----------------------------------------------------

    public function test_an_unrelated_approver_is_refused_and_audited(): void
    {
        $request = $this->leave($this->employee);
        $stranger = $this->member(RoleName::Approver);

        $this->refusedFor($stranger, $request);

        $audit = AuditLog::query()->where('event', AuditEvent::RequestDecisionRefused->value)->sole();
        $this->assertSame($stranger->id, $audit->actor_id);
        $this->assertSame($request->id, $audit->subject_id);
        $this->assertSame(LeaveRequest::class, $audit->subject_type);
        $this->assertEquals(['decision' => 'approved', 'reason' => 'no_authority'], $audit->metadata);
        $this->assertSame(0, DB::table('leave_balance_entries')->where('type', 'deducted')->count());
    }

    public function test_an_employee_without_the_permission_is_refused(): void
    {
        $request = $this->overtime($this->employee);
        $colleague = $this->member(RoleName::Employee);
        $this->supervise($this->supervisor, $colleague);

        $this->refusedFor($colleague, $request);
    }

    public function test_a_supervisor_whose_link_was_removed_after_submission_is_refused(): void
    {
        $second = $this->member(RoleName::Approver);
        $this->supervise($second, $this->employee);
        $request = $this->overtime($this->employee);

        app(Supervisors::class)->detach($this->member(RoleName::HrAdmin), $this->employee, $this->supervisor->id);

        $this->refusedFor($this->supervisor, $request);
        $this->decisions()->approve($second, 'overtime', $request->id);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_a_supervisor_suspended_after_submission_is_refused_even_with_a_stale_instance(): void
    {
        $this->supervise($this->member(RoleName::Approver), $this->employee);
        $request = $this->overtime($this->employee);
        $stale = User::query()->findOrFail($this->supervisor->id);

        $this->supervisor->suspend($this->member(RoleName::HrAdmin));

        $this->refusedFor($stale, $request);
    }

    public function test_the_requester_role_is_read_at_the_moment_of_the_decision(): void
    {
        $request = $this->overtime($this->employee);
        $admin = $this->member(RoleName::HrAdmin);

        // The Employee became an Approver: their requests now go to any HR Admin, not to the old supervisor.
        $this->employee->changeRole(RoleName::Approver, $admin);

        $this->refusedFor($this->supervisor, $request);
        $this->decisions()->approve($admin, 'overtime', $request->id);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_an_unknown_or_forged_request_is_not_found(): void
    {
        $real = $this->overtime($this->employee);

        // "<id>abc" would find the real request through MySQL's cast if it were not refused first.
        foreach ([['leave', 999], ['overtime', 'abc'], ['overtime', -1], ['overtime', $real->id.'abc'], ['holiday', 1]] as [$type, $id]) {
            try {
                $this->decisions()->approve($this->supervisor, $type, $id);
                $this->fail("$type $id was accepted.");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(RequestStatus::Pending, $real->fresh()->status);
    }

    // --- Already decided --------------------------------------------------------------------------

    public function test_a_second_supervisor_learns_who_decided_first(): void
    {
        $second = $this->member(RoleName::Approver);
        $this->supervise($second, $this->employee);
        $request = $this->overtime($this->employee);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 13:45'));
        $this->decisions()->approve($this->supervisor, 'overtime', $request->id);

        foreach (['approve', 'reject'] as $action) {
            $errors = $this->errorsOf(fn () => $this->decisions()->{$action}($second, 'overtime', $request->id, 'Not needed.'));
            $this->assertSame(['This request was already decided: Approved by Siti Supervisor on 4 Nov 2026, 13:45 WIB.'], $errors['decision']);
        }

        $request->refresh();
        $this->assertSame($this->supervisor->id, $request->decided_by);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::OvertimeRequestApproved->value)->count());
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::RequestDecisionRefused->value)->count());
    }

    public function test_a_cancelled_request_says_so(): void
    {
        $request = $this->leave($this->employee);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 11:20'));
        app(LeaveRequests::class)->cancel($this->employee, $request->id);

        $errors = $this->errorsOf(fn () => $this->decisions()->approve($this->supervisor, 'leave', $request->id));

        $this->assertSame(['The requester cancelled this request on 4 Nov 2026, 11:20 WIB.'], $errors['decision']);
    }

    public function test_a_decided_request_cannot_be_cancelled_by_the_requester(): void
    {
        $request = $this->leave($this->employee);
        $this->decisions()->reject($this->supervisor, 'leave', $request->id, 'Team is short that week.');

        $errors = $this->errorsOf(fn () => app(LeaveRequests::class)->cancel($this->employee, $request->id));

        $this->assertSame(['This request is no longer pending: it is Rejected.'], $errors['request']);
    }

    // --- Soft lock --------------------------------------------------------------------------------

    public function test_the_first_decider_holds_the_lock_and_others_see_who(): void
    {
        $second = $this->member(RoleName::Approver, ['name' => 'Budi Second']);
        $this->supervise($second, $this->employee);
        $request = $this->overtime($this->employee);
        $lock = app(ReviewLock::class);

        $mine = $lock->acquire($this->supervisor, OvertimeRequest::class, $request->id);
        $this->assertTrue($mine->isHeldBy($this->supervisor->id));
        $this->assertSame('2026-11-04 10:05:00', $mine->until->toDateTimeString());

        $theirs = $lock->acquire($second, OvertimeRequest::class, $request->id);
        $this->assertFalse($theirs->isHeldBy($second->id));
        $this->assertSame('Siti Supervisor', $theirs->name);

        // View-only means view-only on the server too.
        $errors = $this->errorsOf(fn () => $this->decisions()->approve($second, 'overtime', $request->id));
        $this->assertSame(['Siti Supervisor is reviewing this request until 10:05 WIB. You can view it, but not decide it now.'], $errors['decision']);
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);

        // The holder decides, and the lock goes with the decision.
        $this->decisions()->approve($this->supervisor, 'overtime', $request->id);
        $request->refresh();
        $this->assertNull($request->review_locked_by);
        $this->assertNull($request->review_locked_until);
        $this->assertNull($lock->holder(OvertimeRequest::class, $request->id));
    }

    public function test_a_heartbeat_renews_and_an_expired_lock_can_be_taken_over(): void
    {
        $second = $this->member(RoleName::Approver);
        $this->supervise($second, $this->employee);
        $request = $this->overtime($this->employee);
        $lock = app(ReviewLock::class);

        $lock->acquire($this->supervisor, OvertimeRequest::class, $request->id);
        $this->travel(4)->minutes();
        $this->assertSame('2026-11-04 10:09:00', $lock->acquire($this->supervisor, OvertimeRequest::class, $request->id)->until->toDateTimeString());

        // Renewed, so still held a minute after the first lock would have run out.
        $this->travel(2)->minutes();
        $this->assertFalse($lock->acquire($second, OvertimeRequest::class, $request->id)->isHeldBy($second->id));

        // Expired: nobody holds it, and the next decider takes it.
        $this->travel(3)->minutes();
        $this->assertNull($lock->holder(OvertimeRequest::class, $request->id));
        $this->assertTrue($lock->acquire($second, OvertimeRequest::class, $request->id)->isHeldBy($second->id));

        // The former holder lost it and cannot decide over the new holder.
        $this->assertFalse($lock->acquire($this->supervisor, OvertimeRequest::class, $request->id)->isHeldBy($this->supervisor->id));
        $this->assertArrayHasKey('decision', $this->errorsOf(fn () => $this->decisions()->approve($this->supervisor, 'overtime', $request->id)));
    }

    public function test_an_expired_lock_does_not_stop_a_decision(): void
    {
        $second = $this->member(RoleName::Approver);
        $this->supervise($second, $this->employee);
        $request = $this->overtime($this->employee);

        app(ReviewLock::class)->acquire($this->supervisor, OvertimeRequest::class, $request->id);
        $this->travel(5)->minutes();

        $this->decisions()->approve($second, 'overtime', $request->id);
        $this->assertSame($second->id, $request->fresh()->decided_by);
    }

    public function test_only_the_holder_releases_the_lock(): void
    {
        $second = $this->member(RoleName::Approver);
        $request = $this->overtime($this->employee);
        $lock = app(ReviewLock::class);

        $lock->acquire($this->supervisor, OvertimeRequest::class, $request->id);
        $lock->release($second, OvertimeRequest::class, $request->id);
        $this->assertTrue($lock->holder(OvertimeRequest::class, $request->id)->isHeldBy($this->supervisor->id));

        $lock->release($this->supervisor, OvertimeRequest::class, $request->id);
        $this->assertNull($lock->holder(OvertimeRequest::class, $request->id));
    }

    public function test_a_request_that_is_no_longer_pending_cannot_be_locked(): void
    {
        $request = $this->overtime($this->employee);
        app(OvertimeRequests::class)->cancel($this->employee, $request->id);

        $this->assertNull(app(ReviewLock::class)->acquire($this->supervisor, OvertimeRequest::class, $request->id));
        $this->assertNull($request->fresh()->review_locked_by);
    }

    public function test_a_stale_lock_on_a_decided_row_is_not_shown(): void
    {
        $request = $this->overtime($this->employee);
        DB::table('overtime_requests')->where('id', $request->id)->update([
            'status' => RequestStatus::Approved->value,
            'review_locked_by' => $this->supervisor->id,
            'review_locked_until' => now()->addMinutes(5),
        ]);

        $this->assertNull(app(ReviewLock::class)->holder(OvertimeRequest::class, $request->id));
    }

    public function test_the_lock_does_not_touch_the_request_timestamps(): void
    {
        $request = $this->overtime($this->employee);
        $this->travel(1)->minutes();

        app(ReviewLock::class)->acquire($this->supervisor, OvertimeRequest::class, $request->id);

        $this->assertSame($request->updated_at->toDateTimeString(), $request->fresh()->updated_at->toDateTimeString());
    }
}
