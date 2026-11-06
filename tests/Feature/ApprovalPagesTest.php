<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Livewire\Approvals\Index;
use App\Livewire\Approvals\Show;
use App\Livewire\Requests\Show as RequestShow;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Decisions;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\ReviewLock;
use App\Services\Supervisors;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class ApprovalPagesTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Family matters out of town.';

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        Storage::fake(Evidence::DISK);
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->supervisor = $this->member(RoleName::Approver, ['name' => 'Siti Supervisor']);
        $this->employee = $this->member(RoleName::Employee, ['name' => 'Eko Employee']);
        $this->supervise($this->supervisor, $this->employee);
    }

    private function leave(User $user, string $start = '2026-11-09', string $end = '2026-11-10', mixed $evidence = null): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, ['category' => 'annual', 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON], $evidence);
    }

    private function overtime(User $user, string $start = '2026-11-05 17:00', string $end = '2026-11-05 20:00'): OvertimeRequest
    {
        return app(OvertimeRequests::class)->submit($user, ['start_at' => $start, 'end_at' => $end, 'reason' => self::REASON]);
    }

    private function secondSupervisor(): User
    {
        $second = $this->member(RoleName::Approver, ['name' => 'Budi Second']);
        $this->supervise($second, $this->employee);

        return $second;
    }

    // --- Access -----------------------------------------------------------------------------------

    public function test_only_approvers_and_hr_admins_reach_approvals(): void
    {
        $this->actingAs($this->supervisor)->get(route('approvals.index'))->assertOk()->assertSee('Approvals');
        $this->actingAs($this->member(RoleName::HrAdmin))->get(route('approvals.index'))->assertOk();
        $this->actingAs($this->employee)->get(route('approvals.index'))->assertForbidden();

        $request = $this->leave($this->employee);
        $this->actingAs($this->employee)->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))->assertForbidden();
        $this->actingAs($this->employee)->post(route('approvals.release', ['type' => 'leave', 'id' => $request->id]))->assertForbidden();
    }

    public function test_the_menu_shows_the_entry_with_the_waiting_count_to_deciders_only(): void
    {
        $this->leave($this->employee);
        $this->overtime($this->employee);

        $this->actingAs($this->supervisor)->get(route('dashboard'))
            ->assertSee(route('approvals.index'))
            ->assertSeeInOrder(['data-waiting-count', '2', 'waiting for your decision']);

        $this->actingAs($this->member(RoleName::Approver))->get(route('dashboard'))
            ->assertSee(route('approvals.index'))
            ->assertDontSee('data-waiting-count', false);

        $this->actingAs($this->employee)->get(route('dashboard'))->assertDontSee(route('approvals.index'));
    }

    public function test_someone_who_may_not_decide_gets_a_404_by_url(): void
    {
        $request = $this->leave($this->employee);
        $stranger = $this->member(RoleName::Approver);
        $admin = $this->member(RoleName::HrAdmin);

        foreach ([$stranger, $admin] as $user) {
            $this->actingAs($user)->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
            $this->actingAs($user)->get(route('approvals.show', ['type' => 'overtime', 'id' => $request->id]))->assertNotFound();
            $this->actingAs($user)->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
        }

        $this->actingAs($this->supervisor)->get(route('approvals.show', ['type' => 'leave', 'id' => 999]))->assertNotFound();
    }

    public function test_forged_livewire_calls_without_the_permission_are_forbidden(): void
    {
        $request = $this->leave($this->employee);

        Livewire::actingAs($this->employee)->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->employee)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])->assertForbidden();
    }

    public function test_losing_the_permission_while_a_page_is_open_forbids_the_next_call(): void
    {
        $request = $this->leave($this->employee);
        $inbox = Livewire::actingAs($this->supervisor)->test(Index::class);
        $detail = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        // The supervisor guard needs another supervisor before the role can go.
        $this->secondSupervisor();
        $this->supervisor->changeRole(RoleName::Employee, $this->member(RoleName::HrAdmin));
        $this->actingAs($this->supervisor->fresh());

        $inbox->set('type', 'leave')->assertForbidden();
        $detail->call('heartbeat')->assertForbidden();
    }

    public function test_a_former_decider_without_the_permission_cannot_download_the_evidence(): void
    {
        $this->secondSupervisor();
        $request = $this->leave($this->employee, '2026-11-09', '2026-11-10', UploadedFile::fake()->image('letter.png', 10, 10));
        app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);

        $this->supervisor->changeRole(RoleName::Employee, $this->member(RoleName::HrAdmin));

        $this->actingAs($this->supervisor->fresh())->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
    }

    // --- Inbox ------------------------------------------------------------------------------------

    public function test_the_inbox_lists_what_waits_for_me_oldest_first_and_filters_by_type(): void
    {
        // An Approver can submit only while an HR Admin is active.
        $this->member(RoleName::HrAdmin);
        $first = $this->overtime($this->employee);
        $this->travel(1)->minutes();
        $second = $this->leave($this->employee);
        $this->travel(1)->minutes();
        $third = $this->overtime($this->employee, '2026-11-06 17:00', '2026-11-06 19:00');

        // Not for this supervisor: another team, and a request already cancelled.
        $this->overtime($this->member(RoleName::Approver));
        $cancelled = $this->leave($this->employee, '2026-11-16', '2026-11-16');
        app(LeaveRequests::class)->cancel($this->employee, $cancelled->id);

        Livewire::actingAs($this->supervisor)->test(Index::class)
            ->assertSeeInOrder(["overtime-{$first->id}", "leave-{$second->id}", "overtime-{$third->id}"])
            ->assertDontSee("leave-{$cancelled->id}")
            ->set('type', ['leave'])
            ->assertSee("leave-{$second->id}")
            ->assertDontSee("overtime-{$first->id}")
            ->set('type', ['overtime'])
            ->assertSee("overtime-{$third->id}")
            ->assertDontSee("leave-{$second->id}");
    }

    public function test_hr_admin_sees_the_requests_of_approvers(): void
    {
        $admin = $this->member(RoleName::HrAdmin);
        $approverRequest = $this->overtime($this->supervisor);
        $employeeRequest = $this->leave($this->employee);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee("overtime-{$approverRequest->id}")
            ->assertDontSee("leave-{$employeeRequest->id}");
    }

    public function test_decided_by_me_lists_my_decisions_newest_first(): void
    {
        $older = $this->overtime($this->employee);
        $newer = $this->leave($this->employee);
        $second = $this->secondSupervisor();
        $theirs = $this->leave($this->employee, '2026-11-16', '2026-11-17');

        app(Decisions::class)->reject($this->supervisor, 'overtime', $older->id, 'Not needed this week.');
        $this->travel(1)->minutes();
        app(Decisions::class)->approve($this->supervisor, 'leave', $newer->id);
        app(Decisions::class)->approve($second, 'leave', $theirs->id);

        Livewire::actingAs($this->supervisor)->test(Index::class)
            ->assertSee('Nothing waits for you')
            ->set('tab', 'decided')
            ->assertSeeInOrder(["leave-{$newer->id}", "overtime-{$older->id}"])
            ->assertDontSee("leave-{$theirs->id}")
            ->assertSee('Rejected');
    }

    public function test_the_inbox_refuses_forged_values(): void
    {
        $component = Livewire::actingAs($this->supervisor)->test(Index::class);

        $component->set('type', ['holiday'])->assertOk()->assertSet('type', [])->assertSee('Nothing waits for you');
        $component->set('tab', ['an', 'array'])->assertStatus(422);
    }

    // --- Detail and decisions ---------------------------------------------------------------------

    public function test_the_detail_shows_the_request_the_allocation_and_the_balance(): void
    {
        $request = $this->leave($this->employee, '2026-11-09', '2026-11-13', UploadedFile::fake()->image('letter.png', 10, 10));

        $this->actingAs($this->supervisor)->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))
            ->assertOk()
            ->assertSee('Eko Employee')
            ->assertSee(self::REASON)
            ->assertSee('letter.png')
            ->assertSee("Eko Employee's annual leave balance")
            ->assertSeeInOrder(['Leave balance used per period', '4 Nov 2026', '3 Nov 2027', '5', 'Reserved'])
            ->assertSeeInOrder(['Current period', 'Entitlement', '12 days', 'Used', '0 days', 'In pending requests', '5 days']);

        // The decider may download the evidence.
        $this->actingAs($this->supervisor)->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertOk();
    }

    public function test_the_overtime_detail_shows_the_hours_of_that_day(): void
    {
        $approved = $this->overtime($this->employee, '2026-11-05 06:00', '2026-11-05 08:00');
        app(Decisions::class)->approve($this->supervisor, 'overtime', $approved->id);
        // Pending, across midnight: 3 hours on the 5th and 2 on the 6th.
        $request = $this->overtime($this->employee, '2026-11-05 21:00', '2026-11-06 02:00');
        // Another pending one on the 6th.
        $this->overtime($this->employee, '2026-11-06 17:00', '2026-11-06 18:00');

        Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'overtime', 'id' => (string) $request->id])
            ->assertSeeInOrder(['data-day="2026-11-05"', '2 hours', '3 hours', '5 hours'], false)
            ->assertSeeInOrder(['data-day="2026-11-06"', '0 hours', '3 hours', '3 hours'], false);
    }

    public function test_opening_takes_the_lock_and_approving_through_the_dialog(): void
    {
        $request = $this->leave($this->employee);

        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSet('holdsLock', true)
            ->assertSee('data-approve', false)
            ->call('openApprove')
            ->assertDispatched('open-modal', name: 'decision-approve')
            ->set('note', 'Enjoy the trip.')
            ->call('approve')
            ->assertDispatched('close-modal', name: 'decision-approve')
            ->assertDispatched('toast', message: 'The request was approved.')
            ->assertSet('holdsLock', false)
            ->assertSee('Enjoy the trip.')
            ->assertDontSee('data-approve', false);

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(2, DB::table('leave_periods')->where('user_id', $this->employee->id)->value('used'));
        $component->assertSee('Deducted');
    }

    public function test_rejecting_needs_a_reason_and_the_dialog_stays_open_until_then(): void
    {
        $request = $this->overtime($this->employee);

        Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'overtime', 'id' => (string) $request->id])
            ->call('openReject')
            ->assertDispatched('open-modal', name: 'decision-reject')
            ->set('reason', 'No')
            ->call('reject')
            ->assertHasErrors(['reason'])
            ->assertNotDispatched('close-modal')
            ->set('reason', 'The project ended early.')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'The request was rejected.');

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_a_second_decider_is_view_only_and_sees_who_holds_the_lock(): void
    {
        $second = $this->secondSupervisor();
        $request = $this->leave($this->employee);

        Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        $viewer = Livewire::actingAs($second)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSet('holdsLock', false)
            ->assertSee('Siti Supervisor is reviewing this request until 10:05 WIB.')
            ->assertDontSee('data-approve', false);

        // A forged call from view-only mode is refused on the server.
        $viewer->call('openApprove')->assertNotDispatched('open-modal');
        $viewer->call('approve')
            ->assertSet('notice', 'Siti Supervisor is reviewing this request until 10:05 WIB. You can view it, but not decide it now.');
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);

        // The heartbeat of a view-only page does not take the lock.
        $viewer->call('heartbeat')->assertSet('holdsLock', false);
    }

    public function test_after_the_holder_closes_another_decider_can_start_reviewing(): void
    {
        $second = $this->secondSupervisor();
        $request = $this->leave($this->employee);

        $holder = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);
        $viewer = Livewire::actingAs($second)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        // Livewire::actingAs() switched the logged-in user to the second supervisor; switch back for the holder's page.
        $this->actingAs($this->supervisor);
        $holder->call('close')->assertRedirect(route('approvals.index'));
        $this->actingAs($second);
        $this->assertNull(app(ReviewLock::class)->holder(LeaveRequest::class, $request->id));

        // A free lock is taken only by an explicit Start reviewing, never by the view-only page's poll.
        $viewer->call('heartbeat')->assertSet('holdsLock', false);
        $this->assertNull(app(ReviewLock::class)->holder(LeaveRequest::class, $request->id));

        $viewer->call('$refresh')->assertSee('Nobody is reviewing this request now.')
            ->call('startReview')
            ->assertSet('holdsLock', true)
            ->assertSee('data-approve', false);
    }

    public function test_the_heartbeat_renews_the_lock(): void
    {
        $request = $this->overtime($this->employee);
        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'overtime', 'id' => (string) $request->id]);

        $this->travel(4)->minutes();
        $component->call('heartbeat')->assertSet('holdsLock', true);

        $this->assertSame('2026-11-04 10:09:00', $request->fresh()->review_locked_until->toDateTimeString());
    }

    public function test_a_page_whose_lock_expired_and_was_taken_turns_view_only(): void
    {
        $second = $this->secondSupervisor();
        $request = $this->overtime($this->employee);
        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'overtime', 'id' => (string) $request->id]);

        $this->travel(6)->minutes();
        app(ReviewLock::class)->acquire($second, OvertimeRequest::class, $request->id);

        $component->call('heartbeat')
            ->assertSet('holdsLock', false)
            ->assertSee('Budi Second is reviewing this request')
            ->assertDontSee('data-approve', false);
    }

    public function test_the_release_route_lets_go_of_my_lock_only(): void
    {
        $request = $this->overtime($this->employee);
        $second = $this->secondSupervisor();
        app(ReviewLock::class)->acquire($this->supervisor, OvertimeRequest::class, $request->id);

        $this->actingAs($second)->post(route('approvals.release', ['type' => 'overtime', 'id' => $request->id]))->assertNoContent();
        $this->assertSame($this->supervisor->id, $request->fresh()->review_locked_by);

        $this->actingAs($this->supervisor)->post(route('approvals.release', ['type' => 'overtime', 'id' => $request->id]))->assertNoContent();
        $this->assertNull($request->fresh()->review_locked_by);
    }

    public function test_the_page_releases_the_lock_with_a_beacon_on_pagehide(): void
    {
        $request = $this->overtime($this->employee);

        $this->actingAs($this->supervisor)->get(route('approvals.show', ['type' => 'overtime', 'id' => $request->id]))
            ->assertSee('x-on:pagehide.window', false)
            ->assertSee('navigator.sendBeacon', false)
            ->assertSee('approvals\/overtime\/'.$request->id.'\/release', false)
            ->assertSee('wire:poll.60s="heartbeat"', false);
    }

    public function test_a_decision_made_elsewhere_while_the_page_was_open_is_explained(): void
    {
        $request = $this->leave($this->employee);
        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30'));
        app(LeaveRequests::class)->cancel($this->employee, $request->id);

        $component->call('openApprove')
            ->assertNotDispatched('open-modal')
            ->assertSet('notice', 'The requester cancelled this request on 4 Nov 2026, 10:30 WIB.');
        $component->call('approve')
            ->assertSet('notice', 'The requester cancelled this request on 4 Nov 2026, 10:30 WIB.');
    }

    public function test_a_second_supervisor_opening_a_decided_request_sees_the_outcome(): void
    {
        $second = $this->secondSupervisor();
        $request = $this->leave($this->employee);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 11:00'));
        app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);

        Livewire::actingAs($second)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSee('This request was already decided: Approved by Siti Supervisor on 4 Nov 2026, 11:00 WIB.')
            ->assertSet('holdsLock', false)
            ->assertDontSee('data-approve', false);
    }

    public function test_losing_authority_while_the_page_is_open_ends_cleanly_and_is_audited(): void
    {
        $this->secondSupervisor();
        $request = $this->leave($this->employee);
        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        app(Supervisors::class)->detach($this->member(RoleName::HrAdmin), $this->employee, $this->supervisor->id);

        $component->call('approve')
            ->assertSet('notice', 'You cannot decide this request.')
            ->assertDontSee(self::REASON);
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::RequestDecisionRefused->value)->count());

        $component->call('heartbeat')->assertSet('holdsLock', false)->assertOk();
        $component->call('openReject')->assertNotDispatched('open-modal');
    }

    public function test_the_decider_who_decided_keeps_read_access_after_losing_the_link(): void
    {
        $this->secondSupervisor();
        $request = $this->leave($this->employee);
        app(Decisions::class)->reject($this->supervisor, 'leave', $request->id, 'Busy week for the team.');
        app(Supervisors::class)->detach($this->member(RoleName::HrAdmin), $this->employee, $this->supervisor->id);

        $this->actingAs($this->supervisor)->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))
            ->assertOk()
            ->assertSee('Busy week for the team.');
    }

    public function test_locked_properties_cannot_be_forged(): void
    {
        $request = $this->leave($this->employee);
        $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        foreach (['holdsLock' => true, 'requestId' => 1, 'type' => 'overtime', 'notice' => 'x'] as $property => $value) {
            try {
                $component->set($property, $value);
                $this->fail("$property could be changed.");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }

        $component->set('note', ['an', 'array'])->assertStatus(422);
    }

    // --- The requester's side ---------------------------------------------------------------------

    public function test_the_requester_sees_who_decided_when_and_why(): void
    {
        $request = $this->overtime($this->employee);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 14:15'));
        app(Decisions::class)->reject($this->supervisor, 'overtime', $request->id, 'The project ended early.');

        Livewire::actingAs($this->employee)->test(RequestShow::class, ['type' => 'overtime', 'id' => (string) $request->id])
            ->assertSee('Rejected')
            ->assertSeeInOrder(['Decided by', 'Siti Supervisor', 'Decided', '4 Nov 2026, 14:15 WIB', 'Reason for rejection', 'The project ended early.'])
            ->assertDontSee('data-cancel-request', false);
    }

    public function test_texts_follow_the_language(): void
    {
        $this->supervisor->forceFill(['locale' => 'id'])->save();
        $request = $this->leave($this->employee);

        $this->actingAs($this->supervisor->fresh())->get(route('approvals.show', ['type' => 'leave', 'id' => $request->id]))
            ->assertSee('Setujui')
            ->assertSee('Tolak')
            ->assertSee('Kembali ke persetujuan');
    }
}
