<?php

namespace Tests\Feature;

use App\Actions\CancelRequest;
use App\Actions\DecideRequest;
use App\Actions\SubmitLeaveRequest;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Approvals\Index;
use App\Livewire\Approvals\ReviewLeave;
use App\Livewire\Approvals\ReviewOvertime;
use App\Livewire\Requests\ShowLeave;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\RequestLocks;
use App\Support\SupervisorRelations;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DecidesRequests;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class ApprovalPagesTest extends TestCase
{
    use DecidesRequests, RefreshDatabase, ReplaysLivewire;

    private User $admin;

    private User $supervisor;

    private User $second;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = $this->person(RoleName::HrAdmin);
        $this->supervisor = $this->person(RoleName::Approver);
        $this->second = $this->person(RoleName::Approver);
        $this->employee = $this->linkedTo($this->supervisor);

        SupervisorRelations::assign($this->employee, $this->second);
    }

    private function review(LeaveRequest $request, ?User $actor = null): mixed
    {
        return Livewire::actingAs($actor ?? $this->supervisor)->test(ReviewLeave::class, ['request' => (string) $request->id]);
    }

    public function test_approvers_and_hr_admins_have_the_menu_with_a_count(): void
    {
        $this->leave($this->employee, '2026-06-16');
        $this->overtime($this->employee, '2026-06-17T18:00', '2026-06-17T20:00');

        $this->actingAs($this->supervisor)->get(route('dashboard'))->assertSee('Approvals')->assertSee('2 waiting');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('Approvals')->assertDontSee('waiting');
        $this->actingAs($this->employee)->get(route('dashboard'))->assertDontSee('Approvals');
    }

    public function test_the_list_shows_my_queue_oldest_first_with_both_kinds(): void
    {
        $this->leave($this->employee, '2026-06-16');
        $this->travel(1)->minutes();
        $this->overtime($this->employee, '2026-06-17T18:00', '2026-06-17T20:00');
        $this->leave($this->linkedTo($this->person(RoleName::Approver)), '2026-06-18');

        Livewire::actingAs($this->supervisor)->test(Index::class)
            ->assertSeeInOrder(['Annual leave', 'Overtime'])
            ->assertSee($this->employee->name)
            ->assertDontSee('18 Jun 2026')
            ->set('kindFilter', 'overtime')
            ->assertDontSee('Annual leave')
            ->set('kindFilter', 'leave')
            ->assertSee('Annual leave')
            ->assertDontSee('17 Jun 2026');
    }

    public function test_decided_by_me_lists_only_my_decisions(): void
    {
        $mine = $this->leave($this->employee, '2026-06-16');
        $theirs = $this->leave($this->employee, '2026-06-17');

        app(DecideRequest::class)->approve($mine, $this->supervisor);
        app(DecideRequest::class)->reject($theirs, $this->second, 'Not this week');

        Livewire::actingAs($this->supervisor)->test(Index::class)
            ->assertSee('Nothing is waiting')
            ->call('$set', 'tab', 'decided')
            ->assertSee('16 Jun 2026')
            ->assertSee('Approved')
            ->assertDontSee('17 Jun 2026');
    }

    public function test_unknown_filters_are_ignored(): void
    {
        Livewire::actingAs($this->supervisor)->test(Index::class)
            ->set('tab', 'nonsense')->assertSet('tab', 'pending')
            ->set('kindFilter', 'nonsense')->assertSet('kindFilter', '');
    }

    public function test_the_detail_page_shows_what_a_decider_needs(): void
    {
        Storage::fake('evidence');

        $path = tempnam(sys_get_temp_dir(), 'evidence');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $request = app(SubmitLeaveRequest::class)->handle(
            $this->employee,
            $this->leaveInput('2026-06-16', '2026-06-17'),
            new UploadedFile($path, 'x.pdf', null, null, true),
        );

        $this->actingAs($this->supervisor)->get(route('approvals.leave', $request->id))
            ->assertOk()
            ->assertSee($this->employee->name)
            ->assertSee('Annual leave')
            ->assertSee(self::VALID_REASON)
            ->assertSee('15 Jun 2026 to 14 Jun 2027')
            ->assertSee('12 days')
            ->assertSee(route('requests.leave.evidence', $request->id))
            ->assertSee('request-approve');

        $this->actingAs($this->supervisor)->get(route('requests.leave.evidence', $request->id))->assertOk();
    }

    public function test_a_period_not_open_yet_is_marked_on_the_page(): void
    {
        $this->review($this->leave($this->employee, '2027-06-21'))->assertSee('(not open yet)');
    }

    public function test_overtime_shows_the_total_of_each_day_it_touches_clipped_to_that_day(): void
    {
        $this->overtime($this->employee, '2026-06-16T06:00', '2026-06-16T08:00');
        $cancelled = $this->overtime($this->employee, '2026-06-16T09:00', '2026-06-16T12:00');
        $cancelled->forceFill(['status' => RequestStatus::Cancelled])->save();
        $request = $this->overtime($this->employee, '2026-06-16T22:00', '2026-06-17T02:00');

        Livewire::actingAs($this->supervisor)->test(ReviewOvertime::class, ['request' => (string) $request->id])
            ->assertSee('Overtime on 16 Jun 2026')
            ->assertSee('4 hours in total')
            ->assertSee('Overtime on 17 Jun 2026')
            ->assertSee('2 hours in total');
    }

    public function test_overtime_ending_at_midnight_shows_one_day(): void
    {
        $request = $this->overtime($this->employee, '2026-06-16T20:00', '2026-06-17T00:00');

        Livewire::actingAs($this->supervisor)->test(ReviewOvertime::class, ['request' => (string) $request->id])
            ->assertSee('Overtime on 16 Jun 2026')
            ->assertDontSee('Overtime on 17 Jun 2026');
    }

    public function test_approve_with_a_note_shows_to_the_requester(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->set('note', '  Enjoy  ')->call('approve')->assertHasNoErrors()->assertSet('notice', '');

        $this->assertSame('Enjoy', $request->fresh()->decision_note);

        $this->actingAs($this->employee)->get(route('requests.leave.show', $request->id))
            ->assertSee('Approved by')
            ->assertSee($this->supervisor->name)
            ->assertSee('Enjoy');
    }

    public function test_reject_needs_a_reason_of_five_characters_and_shows_it_to_the_requester(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->set('reason', '')->call('reject')->assertHasErrors(['reason' => 'required']);
        $this->review($request)->set('reason', ' abcd ')->call('reject')->assertHasErrors(['reason' => 'min']);
        $this->review($request)->set('reason', str_repeat('a', 1001))->call('reject')->assertHasErrors(['reason' => 'max']);

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);

        $this->review($request)->set('reason', 'abcde')->call('reject')->assertHasNoErrors();

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('abcde', $request->fresh()->rejection_reason);

        $this->actingAs($this->employee)->get(route('requests.leave.show', $request->id))
            ->assertSee('Reason for rejection')
            ->assertSee('abcde');
    }

    public function test_overtime_goes_through_the_same_decision_without_a_balance(): void
    {
        $request = $this->overtime($this->employee, '2026-06-16T18:00', '2026-06-16T20:00');

        Livewire::actingAs($this->supervisor)->test(ReviewOvertime::class, ['request' => (string) $request->id])->call('approve');

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(0, $this->employee->leavePeriods()->sole()->used);

        $rejected = $this->overtime($this->employee, '2026-06-17T18:00', '2026-06-17T20:00');

        Livewire::actingAs($this->supervisor)->test(ReviewOvertime::class, ['request' => (string) $rejected->id])->call('reject')->assertHasErrors('reason');
    }

    public function test_a_decision_is_audited_without_the_text(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->set('reason', 'A private family matter')->call('reject');

        $entry = AuditLog::where('event', AuditEvent::RequestRejected->value)->sole();

        $this->assertSame($this->supervisor->id, $entry->actor_id);
        $this->assertSame($request->id, $entry->subject_id);
        $this->assertStringNotContainsString('private', json_encode($entry->metadata));
    }

    public function test_someone_arriving_late_is_told_who_decided_without_an_error(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = $this->review($request, $this->second);

        RequestLocks::clear($request);
        app(DecideRequest::class)->approve($request, $this->supervisor);

        $page->call('approve')->assertSuccessful();

        $this->assertStringContainsString('Already approved by '.$this->supervisor->name.' at 15 Jun 2026, 09:00 WIB', $page->get('notice'));
        $this->assertSame($this->supervisor->id, $request->fresh()->decided_by);
        $this->assertSame(1, $this->employee->leavePeriods()->sole()->used);
    }

    public function test_a_decision_on_a_cancelled_request_says_so_and_changes_nothing(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = $this->review($request);

        app(CancelRequest::class)->handle($request, $this->employee);

        $page->call('approve');

        $this->assertStringContainsString('already cancelled', $page->get('notice'));
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame(0, $this->employee->leavePeriods()->sole()->used);
    }

    public function test_cancelling_after_a_decision_says_who_decided(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $request->id]);

        app(DecideRequest::class)->approve($request, $this->supervisor);

        $page->call('cancel');

        $this->assertStringContainsString('Already approved by '.$this->supervisor->name, $page->get('notice'));
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_a_requester_who_became_inactive_keeps_the_request_pending(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->employee->suspend($this->admin);

        $message = app(DecideRequest::class)->approve($request, $this->supervisor);

        $this->assertStringContainsString('not active', $message);
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    public function test_opening_takes_the_lock_and_others_only_read(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->assertViewHas('mayDecide', true);

        $other = $this->review($request, $this->second);

        $other->assertViewHas('mayDecide', false)
            ->assertSee($this->supervisor->name.' has this request open')
            ->assertDontSee("name: 'request-approve'", false);

        $this->assertSame($this->supervisor->id, RequestLocks::holder($request)->user_id);
    }

    public function test_the_server_refuses_a_decision_while_someone_else_holds_the_lock(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request);
        $page = $this->review($request, $this->second)->call('approve');

        $this->assertStringContainsString($this->supervisor->name.' has this request open', $page->get('notice'));
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
    }

    public function test_my_own_lock_does_not_block_my_other_tab(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request);

        $this->review($request)->assertViewHas('mayDecide', true)->call('approve')->assertSet('notice', '');

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_a_heartbeat_keeps_the_lock_past_its_first_expiry(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = $this->review($request);

        foreach (range(1, 4) as $beat) {
            $this->travel(4)->minutes();
            $page->call('heartbeat');
        }

        $this->review($request, $this->second)->assertViewHas('mayDecide', false);
    }

    public function test_a_heartbeat_extends_the_lock_from_now(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = $this->review($request);

        $this->travel(4)->minutes();
        $page->call('heartbeat');
        $this->travel(4)->minutes();

        $this->review($request, $this->second)->assertViewHas('mayDecide', false);
    }

    public function test_an_expired_lock_holds_nothing_back_even_before_anyone_takes_it(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->review($request);

        $this->travel(RequestLocks::TTL_SECONDS + 1)->seconds();

        $this->assertNull(RequestLocks::holder($request));
        $this->assertNull(app(DecideRequest::class)->approve($request, $this->second));
    }

    public function test_opening_a_decided_request_takes_no_lock(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        app(DecideRequest::class)->approve($request, $this->supervisor);

        $this->review($request, $this->second);

        $this->assertSame(0, DB::table('request_locks')->count());
    }

    public function test_a_lost_permission_stops_the_page_whatever_the_relation_says(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        Role::findByName(RoleName::Approver->value)->revokePermissionTo(PermissionName::RequestsDecide->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->review($request, $this->supervisor->fresh())->assertForbidden();
    }

    public function test_an_expired_lock_is_taken_over_on_the_next_visit(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->review($request);

        $this->travel(RequestLocks::TTL_SECONDS + 1)->seconds();

        $this->review($request, $this->second)->assertViewHas('mayDecide', true);

        $this->assertSame($this->second->id, RequestLocks::holder($request)->user_id);
    }

    public function test_someone_reading_takes_over_through_the_heartbeat(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->review($request);
        $other = $this->review($request, $this->second);

        $this->travel(RequestLocks::TTL_SECONDS + 1)->seconds();

        $other->call('heartbeat')->assertViewHas('mayDecide', true);
    }

    public function test_a_lock_can_be_released_only_by_its_holder(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->review($request);

        $this->actingAs($this->second)->post(route('approvals.release', ['leave', $request->id]))->assertNoContent();
        $this->assertNotNull(RequestLocks::holder($request));

        $this->actingAs($this->supervisor)->post(route('approvals.release', ['leave', $request->id]))->assertNoContent();
        $this->assertNull(RequestLocks::holder($request));
    }

    public function test_the_page_releases_its_lock_as_it_closes(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->actingAs($this->supervisor)->get(route('approvals.leave', $request->id))
            ->assertSee('navigator.sendBeacon', false)
            ->assertSee('release', false);
    }

    public function test_a_decided_request_has_no_lock_or_actions(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->call('approve')
            ->assertViewHas('mayDecide', false)
            ->assertDontSee("name: 'request-approve'", false);

        $this->assertSame(0, DB::table('request_locks')->count());
    }

    public function test_a_cancelled_request_drops_its_lock(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->review($request);

        Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $request->id])->call('cancel');

        $this->assertSame(0, DB::table('request_locks')->count());
    }

    public function test_the_dialogs_stay_in_the_page_after_a_decision(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->review($request)->call('approve')->assertSee('request-approve-title', false)->assertSee('request-reject-title', false);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function outsiders(): array
    {
        return ['Employee' => [RoleName::Employee], 'unrelated Approver' => [RoleName::Approver], 'HR Admin' => [RoleName::HrAdmin]];
    }

    #[DataProvider('outsiders')]
    public function test_an_outsider_cannot_open_a_request_and_an_unknown_id_looks_the_same(RoleName $role): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $user = $this->person($role);

        $this->actingAs($user)->get(route('approvals.leave', $request->id))->assertForbidden();

        if ($role !== RoleName::Employee) {
            $this->actingAs($user)->get(route('approvals.leave', 999_999))->assertForbidden();
        }

        $this->assertSame(0, DB::table('request_locks')->count());
    }

    public function test_the_requester_cannot_open_their_own_request_here(): void
    {
        SupervisorRelations::assign($this->admin, $this->supervisor);

        $request = $this->leave($this->admin, '2026-06-16');

        $this->actingAs($this->admin)->get(route('approvals.leave', $request->id))->assertForbidden();
    }

    public function test_an_employee_cannot_reach_any_approval_route(): void
    {
        $this->actingAs($this->employee)->get(route('approvals.index'))->assertForbidden();
        $this->actingAs($this->employee)->post(route('approvals.release', ['leave', 1]))->assertForbidden();
        Livewire::actingAs($this->employee)->test(Index::class)->assertForbidden();
    }

    public function test_every_route_carries_its_permission(): void
    {
        foreach (['approvals.index', 'approvals.leave', 'approvals.overtime', 'approvals.release'] as $name) {
            $this->assertContains('can:'.PermissionName::RequestsDecide->value, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_a_replayed_snapshot_is_refused_and_nothing_moves(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $snapshot = $this->snapshotOf($this->supervisor, route('approvals.leave', $request->id));

        RequestLocks::clear($request);

        $this->actingAs($this->employee);
        $this->callLivewire($snapshot, 'approve')->assertForbidden();

        $this->actingAs($this->person(RoleName::HrAdmin));
        $this->callLivewire($snapshot, 'approve')->assertForbidden();
        $this->callLivewire($snapshot, 'heartbeat')->assertForbidden();

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(0, DB::table('request_locks')->count());
    }

    public function test_a_replay_by_a_decider_of_another_person_is_audited(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $page = $this->review($request);

        RequestLocks::clear($request);
        SupervisorRelations::remove($this->employee, $this->supervisor, $this->admin);

        $page->call('approve')->assertForbidden();

        $this->assertSame(1, AuditLog::where('event', AuditEvent::RequestDecisionForbidden->value)->where('actor_id', $this->supervisor->id)->count());
    }

    public function test_the_action_itself_refuses_a_stranger_after_a_manipulated_page(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->expectException(AuthorizationException::class);

        app(DecideRequest::class)->approve($request, $this->employee);
    }

    public function test_the_request_id_is_locked(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->review($request)->set('requestId', 1);
    }

    public function test_arrays_for_fields_are_refused(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $snapshot = $this->snapshotOf($this->supervisor, route('approvals.leave', $request->id));

        $this->callLivewire($snapshot, null, [], ['reason' => ['x']])->assertStatus(422);
    }

    public function test_a_supervisor_who_lost_the_role_cannot_reach_the_page(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        $this->supervisor->changeRole(RoleName::Employee, $this->admin);

        $this->actingAs($this->supervisor->fresh())->get(route('approvals.leave', $request->id))->assertForbidden();
    }

    public function test_who_decided_can_still_read_it_after_losing_the_relation(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');
        app(DecideRequest::class)->approve($request, $this->supervisor);

        SupervisorRelations::remove($this->employee, $this->supervisor, $this->admin);

        $this->actingAs($this->supervisor)->get(route('approvals.leave', $request->id))->assertOk();
    }

    public function test_a_suspended_decider_status_is_read_at_submit(): void
    {
        $request = $this->leave($this->employee, '2026-06-16');

        $this->supervisor->forceFill(['status' => UserStatus::Pending])->save();

        $this->expectException(AuthorizationException::class);

        app(DecideRequest::class)->approve($request, $this->supervisor);
    }
}
