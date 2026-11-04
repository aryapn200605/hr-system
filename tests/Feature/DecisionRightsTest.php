<?php

namespace Tests\Feature;

use App\Actions\DecideRequest;
use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\RequestDecisions;
use App\Support\SupervisorRelations;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DecidesRequests;
use Tests\TestCase;

class DecisionRightsTest extends TestCase
{
    use DecidesRequests, RefreshDatabase;

    private User $admin;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = $this->person(RoleName::HrAdmin);
        $this->supervisor = $this->person(RoleName::Approver);
    }

    private function requestOf(User $user): LeaveRequest
    {
        return $this->leave($user, '2026-06-16');
    }

    private function suspendDirectly(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['status' => UserStatus::Suspended->value]);
    }

    private function changeRoleDirectly(User $user, RoleName $role): void
    {
        DB::table('model_has_roles')->where('model_id', $user->id)->update([
            'role_id' => DB::table('roles')->where('name', $role->value)->value('id'),
        ]);
    }

    public function test_an_employee_is_decided_by_their_active_approver_only(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $request = $this->requestOf($employee);

        $this->assertTrue(RequestDecisions::canDecide($this->supervisor, $request));
        $this->assertFalse(RequestDecisions::canDecide($this->person(RoleName::Approver), $request));
        $this->assertFalse(RequestDecisions::canDecide($this->admin, $request));
        $this->assertFalse(RequestDecisions::canDecide($this->person(RoleName::Employee), $request));
        $this->assertFalse(RequestDecisions::canDecide($employee, $request));
    }

    public function test_an_approver_is_decided_by_any_active_hr_admin_and_no_approver(): void
    {
        $requester = $this->linkedTo($this->supervisor, RoleName::Approver);
        $request = $this->requestOf($requester);

        $this->assertTrue(RequestDecisions::canDecide($this->admin, $request));
        $this->assertTrue(RequestDecisions::canDecide($this->person(RoleName::HrAdmin), $request));
        $this->assertFalse(RequestDecisions::canDecide($this->supervisor, $request));
        $this->assertFalse(RequestDecisions::canDecide($requester, $request));
    }

    public function test_an_hr_admin_is_decided_by_their_approver_and_never_by_hr_admin(): void
    {
        SupervisorRelations::assign($this->admin, $this->supervisor);
        $request = $this->requestOf($this->admin);

        $this->assertTrue(RequestDecisions::canDecide($this->supervisor, $request));
        $this->assertFalse(RequestDecisions::canDecide($this->person(RoleName::HrAdmin), $request));
        $this->assertFalse(RequestDecisions::canDecide($this->admin, $request));
        $this->assertFalse(RequestDecisions::canDecide($this->person(RoleName::Approver), $request));
    }

    public function test_a_supervisor_without_the_approver_role_cannot_decide(): void
    {
        $request = $this->requestOf($this->linkedTo($this->supervisor));

        $this->changeRoleDirectly($this->supervisor, RoleName::Employee);

        $this->assertFalse(RequestDecisions::canDecide($this->supervisor->fresh(), $request));
    }

    public function test_a_suspended_supervisor_cannot_decide(): void
    {
        $request = $this->requestOf($this->linkedTo($this->supervisor));

        $this->suspendDirectly($this->supervisor);

        $this->assertFalse(RequestDecisions::canDecide($this->supervisor->fresh(), $request));
    }

    public function test_a_suspended_hr_admin_cannot_decide(): void
    {
        $request = $this->requestOf($this->linkedTo($this->supervisor, RoleName::Approver));

        $this->suspendDirectly($this->admin);

        $this->assertFalse(RequestDecisions::canDecide($this->admin->fresh(), $request));
    }

    public function test_rights_follow_the_current_role_of_the_requester(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $request = $this->requestOf($employee);

        $this->changeRoleDirectly($employee, RoleName::Approver);

        $this->assertFalse(RequestDecisions::canDecide($this->supervisor, $request));
        $this->assertTrue(RequestDecisions::canDecide($this->admin, $request));
    }

    public function test_rights_follow_the_current_relation(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $request = $this->requestOf($employee);
        $other = $this->person(RoleName::Approver);

        SupervisorRelations::move($this->supervisor, $other, null);

        $this->assertFalse(RequestDecisions::canDecide($this->supervisor, $request));
        $this->assertTrue(RequestDecisions::canDecide($other, $request));
    }

    public function test_a_requester_without_a_role_cannot_be_decided(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $request = $this->requestOf($employee);

        DB::table('model_has_roles')->where('model_id', $employee->id)->delete();

        $this->assertFalse(RequestDecisions::canDecide($this->supervisor, $request));
    }

    public function test_a_decision_without_the_right_is_refused_changes_nothing_and_is_audited(): void
    {
        $request = $this->requestOf($this->linkedTo($this->supervisor));
        $stranger = $this->person(RoleName::Approver);

        try {
            app(DecideRequest::class)->approve($request, $stranger);
            $this->fail('The decision went through.');
        } catch (AuthorizationException) {
            $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
            $this->assertSame(0, $request->user->leavePeriods()->sole()->used);
        }

        $entry = AuditLog::where('event', AuditEvent::RequestDecisionForbidden->value)->sole();

        $this->assertSame($stranger->id, $entry->actor_id);
        $this->assertSame($request->id, $entry->subject_id);
    }

    public function test_a_requester_cannot_decide_their_own_request(): void
    {
        $employee = $this->linkedTo($this->supervisor, RoleName::Approver);
        SupervisorRelations::assign($this->admin, $this->supervisor);
        $request = $this->requestOf($this->admin);

        $this->expectException(AuthorizationException::class);

        app(DecideRequest::class)->reject($request, $this->admin, 'Not allowed');

        $this->assertNotNull($employee);
    }

    public function test_a_right_taken_away_after_the_page_was_opened_counts_at_submit(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $other = $this->person(RoleName::Approver);
        $request = $this->requestOf($employee);

        $this->assertTrue(RequestDecisions::canDecide($this->supervisor, $request));

        SupervisorRelations::assign($employee, $other);
        SupervisorRelations::remove($employee, $this->supervisor, $this->admin);

        $this->expectException(AuthorizationException::class);

        app(DecideRequest::class)->approve($request, $this->supervisor);
    }

    public function test_the_queue_holds_only_what_this_person_decides(): void
    {
        $mine = $this->requestOf($this->linkedTo($this->supervisor));
        $hers = $this->requestOf($this->linkedTo($this->person(RoleName::Approver)));
        $approverRequest = $this->requestOf($this->linkedTo($this->supervisor, RoleName::Approver));
        $this->requestOf($this->linkedTo($this->supervisor))->forceFill(['status' => RequestStatus::Cancelled])->save();

        $this->assertSame([$mine->id], RequestDecisions::queue($this->supervisor, LeaveRequest::class)->pluck('id')->all());
        $this->assertSame([$approverRequest->id], RequestDecisions::queue($this->admin, LeaveRequest::class)->pluck('id')->all());
        $this->assertSame(1, RequestDecisions::pendingCount($this->supervisor));
        $this->assertNotNull($hers);
    }

    public function test_someone_inactive_has_an_empty_queue(): void
    {
        $this->requestOf($this->linkedTo($this->supervisor));

        $this->suspendDirectly($this->supervisor);

        $this->assertSame(0, RequestDecisions::pendingCount($this->supervisor->fresh()));
    }

    public function test_the_queue_goes_oldest_first(): void
    {
        $employee = $this->linkedTo($this->supervisor);
        $first = $this->leave($employee, '2026-06-20', '2026-06-22');
        $this->travel(1)->minutes();
        $second = $this->leave($employee, '2026-06-16');

        $this->assertSame([$first->id, $second->id], RequestDecisions::queue($this->supervisor, LeaveRequest::class)->pluck('id')->all());
    }
}
