<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Dashboard;
use App\Models\LeavePeriod;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Requests\Deciders;
use App\Services\Requests\Decisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

/**
 * The request and leave numbers of the dashboard (Phase 12), on the organization of SeedsReportData:
 * Eko's overtime and Rina's sick leave are pending; Eko's leave and Siti's overtime are approved; Hana's
 * leave is rejected. Siti decides Eko, Budi decides Rina and Hana, Hana decides the Approvers.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReportData();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return Livewire::actingAs($user)->test(Dashboard::class)->viewData('summary');
    }

    /**
     * The numbers only, with the last decision as "type-id status".
     *
     * @return array<string, mixed>
     */
    private function numbers(User $user): array
    {
        $summary = $this->summary($user);
        $last = $summary['lastDecided'];

        return [
            'available' => $summary['balance']?->available(),
            'ownPending' => $summary['ownPending'],
            'lastDecided' => $last ? $last->type.'-'.$last->id.' '.$last->status->value : null,
            'waitingForMe' => $summary['waitingForMe'],
            'pendingTotal' => $summary['pendingTotal'],
            'withoutSupervisor' => $summary['withoutSupervisor'],
        ];
    }

    private function key(string $name): string
    {
        $request = $this->requests[$name];

        return ($request instanceof LeaveRequest ? 'leave' : 'overtime').'-'.$request->id;
    }

    public function test_each_role_sees_its_own_numbers(): void
    {
        $this->assertSame([
            'available' => 10,
            'ownPending' => 1,
            'lastDecided' => $this->key('eko-leave').' approved',
            'waitingForMe' => null,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($this->eko));

        $this->assertSame([
            'available' => 12,
            'ownPending' => 1,
            'lastDecided' => null,
            'waitingForMe' => null,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($this->rina));

        $this->assertSame([
            'available' => 12,
            'ownPending' => 0,
            'lastDecided' => $this->key('siti-overtime').' approved',
            'waitingForMe' => 1,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($this->siti));

        $this->assertSame([
            'available' => 12,
            'ownPending' => 0,
            'lastDecided' => null,
            'waitingForMe' => 1,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($this->budi));

        $this->assertSame([
            'available' => 12,
            'ownPending' => 0,
            'lastDecided' => $this->key('hana-leave').' rejected',
            'waitingForMe' => 0,
            'pendingTotal' => 2,
            'withoutSupervisor' => 0,
        ], $this->numbers($this->hana));
    }

    public function test_the_balance_is_the_current_period_of_my_requests(): void
    {
        $balance = $this->summary($this->eko)['balance'];
        $period = LeavePeriod::query()->where('user_id', $this->eko->id)->sole();

        $this->assertSame($period->id, $balance->id);
        $this->assertSame($period->starts_on->toDateString(), $balance->startsOn->toDateString());
        $this->assertSame($period->ends_on->toDateString(), $balance->endsOn->toDateString());

        // Days held by a pending annual leave are not available, as on My requests.
        $this->leaveRequest($this->eko, 'annual', '2026-11-11', '2026-11-13');
        $balance = $this->summary($this->eko)['balance'];

        $this->assertSame(3, $balance->pending);
        $this->assertSame(7, $balance->available());

        Livewire::actingAs($this->eko)->test(Dashboard::class)
            ->assertSeeHtml('data-balance-period')
            ->assertSee($period->starts_on->translatedFormat('j M Y'))
            ->assertSee($period->ends_on->translatedFormat('j M Y'))
            ->assertSee('3 days are held by pending requests.');
    }

    public function test_own_numbers_never_count_other_users_requests(): void
    {
        // More pending requests and a later decision, all someone else's.
        $this->rawOvertime($this->rina, '2026-12-01 08:00', 2, RequestStatus::Pending);
        $this->rawOvertime($this->siti, '2026-12-02 08:00', 2, RequestStatus::Pending);
        $this->travel(1)->hours();
        app(Decisions::class)->approve($this->budi, 'leave', $this->requests['rina-leave']->id);
        $this->rawOvertime($this->budi, '2026-12-03 08:00', 2, RequestStatus::Rejected, $this->hana);

        $eko = $this->numbers($this->eko);
        $this->assertSame(1, $eko['ownPending']);
        $this->assertSame($this->key('eko-leave').' approved', $eko['lastDecided']);
        $this->assertSame(10, $eko['available']);

        $this->assertSame(1, $this->numbers($this->rina)['ownPending']);
        $this->assertSame($this->key('rina-leave').' approved', $this->numbers($this->rina)['lastDecided']);
        $this->assertSame(1, $this->numbers($this->siti)['ownPending']);
    }

    public function test_the_last_decision_ignores_pending_and_cancelled_requests_and_takes_the_latest(): void
    {
        $this->travel(1)->hours();
        $cancelled = $this->rawOvertime($this->eko, '2026-12-01 08:00', 2, RequestStatus::Cancelled);
        $cancelled->forceFill(['decided_at' => now()->addHour()])->save();

        $this->assertSame($this->key('eko-leave').' approved', $this->numbers($this->eko)['lastDecided']);

        // A request with a higher ID but decided earlier does not count as the last decision.
        $earlier = $this->rawOvertime($this->eko, '2026-12-03 08:00', 2, RequestStatus::Approved, $this->siti);
        $earlier->forceFill(['decided_at' => now()->subYear()])->save();
        $this->assertSame($this->key('eko-leave').' approved', $this->numbers($this->eko)['lastDecided']);

        $this->travel(1)->hours();
        $rejected = $this->rawOvertime($this->eko, '2026-12-02 08:00', 2, RequestStatus::Rejected, $this->siti);

        $this->assertSame('overtime-'.$rejected->id.' rejected', $this->numbers($this->eko)['lastDecided']);

        Livewire::actingAs($this->eko)->test(Dashboard::class)
            ->assertSeeHtml('href="'.route('requests.show', ['type' => 'overtime', 'id' => $rejected->id]).'"')
            ->assertDontSee(self::REASON);
    }

    public function test_waiting_for_me_follows_the_deciders_rule_as_it_is_now(): void
    {
        foreach ([$this->siti, $this->budi, $this->hana] as $decider) {
            $this->assertSame(app(Deciders::class)->waitingCount($decider), $this->numbers($decider)['waitingForMe']);
        }

        // An Approver's pending request waits for every HR Admin, not for the Approver who supervises them.
        $this->supervise($this->budi, $this->siti);
        $this->rawOvertime($this->siti, '2026-12-02 08:00', 2, RequestStatus::Pending);

        $this->assertSame(1, $this->numbers($this->budi)['waitingForMe']);
        $this->assertSame(1, $this->numbers($this->hana)['waitingForMe']);

        // A removed link removes the count at once.
        DB::table('supervisor_user')->where('supervisor_id', $this->siti->id)->delete();
        $this->assertSame(0, $this->numbers($this->siti)['waitingForMe']);
    }

    public function test_hr_admin_counts_every_pending_request_and_users_without_a_supervisor(): void
    {
        $this->rawOvertime($this->siti, '2026-12-02 08:00', 2, RequestStatus::Pending);
        $this->rawLeave($this->rina, '2026-12-03', '2026-12-03', 'sick', RequestStatus::Cancelled);

        // Employees and HR Admins without an active supervisor count; Approvers and suspended users do not.
        $this->member(RoleName::Employee);
        $this->member(RoleName::HrAdmin);
        $this->member(RoleName::Approver);
        $this->member(RoleName::Employee, ['status' => UserStatus::Suspended]);
        $suspendedSupervisor = $this->member(RoleName::Approver, ['status' => UserStatus::Suspended]);
        $this->supervise($suspendedSupervisor, $this->member(RoleName::Employee));

        $numbers = $this->numbers($this->hana);
        $this->assertSame(3, $numbers['pendingTotal']);
        $this->assertSame(3, $numbers['withoutSupervisor']);
        $this->assertSame(User::query()->lackingSupervisor()->count(), $numbers['withoutSupervisor']);

        Livewire::actingAs($this->hana)->test(Dashboard::class)
            ->assertSeeHtml('href="'.route('users.index', ['supervisor' => 'none']).'"')
            ->assertSeeHtml('href="'.route('history.index', ['status' => 'pending']).'"');
    }

    public function test_numbers_follow_the_permissions_not_the_role_name(): void
    {
        // Revoking the permission from the role hides the number, whatever the role is called.
        Role::findByName(RoleName::HrAdmin->value)->revokePermissionTo(PermissionName::RequestsViewAll->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull($this->numbers($this->hana->fresh())['pendingTotal']);

        // Seeing everyone gives the organization total, not an own count.
        $auditor = User::factory()->twoFactorEnrolled()->create();
        $auditor->givePermissionTo([PermissionName::DashboardView, PermissionName::RequestsViewAll]);
        $this->rawOvertime($auditor, '2026-12-01 08:00', 2, RequestStatus::Pending);
        $auditorNumbers = $this->numbers($auditor);
        $this->assertNull($auditorNumbers['ownPending']);
        $this->assertSame(3, $auditorNumbers['pendingTotal']);

        // Without requests.submit, a user with decided and pending requests gets none of their own numbers.
        Role::findByName(RoleName::Employee->value)->revokePermissionTo(PermissionName::RequestsSubmit->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $eko = $this->numbers($this->eko->fresh());
        $this->assertNull($eko['available']);
        $this->assertNull($eko['ownPending']);
        $this->assertNull($eko['lastDecided']);

        // A user with only the dashboard permission gets no request numbers and no leave period.
        $viewer = User::factory()->twoFactorEnrolled()->create();
        $viewer->givePermissionTo(PermissionName::DashboardView);
        $periods = LeavePeriod::query()->count();
        $this->travel(2)->years();

        $this->assertSame([
            'available' => null,
            'ownPending' => null,
            'lastDecided' => null,
            'waitingForMe' => null,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($viewer));
        // Without requests.submit the balance is not read, so no period is opened either.
        $this->assertSame($periods, LeavePeriod::query()->count());

        // Nor is any request table read.
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($viewer)->test(Dashboard::class);
        $reads = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], '_requests'));
        DB::disableQueryLog();
        $this->assertCount(0, $reads);

        Livewire::actingAs($viewer)->test(Dashboard::class)->assertDontSeeHtml('data-requests-summary');
    }

    public function test_numbers_a_user_is_not_entitled_to_are_not_rendered(): void
    {
        $eko = Livewire::actingAs($this->eko)->test(Dashboard::class);
        $eko->assertSeeHtml('data-balance')->assertSeeHtml('data-own-pending')->assertSeeHtml('data-last-decided');

        foreach (['data-waiting', 'data-pending-total', 'data-without-supervisor'] as $marker) {
            $eko->assertDontSeeHtml($marker);
        }

        Livewire::actingAs($this->siti)->test(Dashboard::class)
            ->assertSeeHtml('data-waiting-link')
            ->assertSeeHtml('href="'.route('approvals.index').'"')
            ->assertDontSeeHtml('data-pending-total')
            ->assertDontSeeHtml('data-without-supervisor');
    }

    public function test_a_new_user_sees_clean_empty_states(): void
    {
        $new = $this->supervisedMember();

        $this->assertSame([
            'available' => 12,
            'ownPending' => 0,
            'lastDecided' => null,
            'waitingForMe' => null,
            'pendingTotal' => null,
            'withoutSupervisor' => null,
        ], $this->numbers($new));

        Livewire::actingAs($new)->test(Dashboard::class)
            ->assertSee('No decision yet')
            ->assertSee('None of your requests is waiting for a decision.')
            ->assertDontSeeHtml('data-own-pending-link');

        // Nothing waiting and nobody without a supervisor: plain sentences, no links.
        DB::table('overtime_requests')->update(['status' => RequestStatus::Cancelled->value]);
        DB::table('leave_requests')->where('status', RequestStatus::Pending->value)->update(['status' => RequestStatus::Cancelled->value]);

        Livewire::actingAs($this->hana)->test(Dashboard::class)
            ->assertSee('Nothing is waiting for your decision.')
            ->assertSee('No request is waiting for a decision.')
            ->assertSee('Every Employee and HR Admin has an active supervisor.')
            ->assertDontSeeHtml('data-pending-total-link')
            ->assertDontSeeHtml('data-without-supervisor-link');
    }

    public function test_the_page_renders_for_every_role_in_both_languages(): void
    {
        foreach ([$this->eko, $this->siti, $this->hana] as $user) {
            $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->assertSee('Requests and leave');
        }

        $this->hana->forceFill(['locale' => 'id'])->save();
        $this->actingAs($this->hana->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pengajuan dan cuti')
            ->assertSee('Karyawan yang belum punya atasan');
    }

    public function test_the_page_runs_the_same_queries_for_more_data(): void
    {
        $count = function (User $user): int {
            Livewire::actingAs($user)->test(Dashboard::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($user)->test(Dashboard::class);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $before = ['hana' => $count($this->hana), 'siti' => $count($this->siti), 'eko' => $count($this->eko)];

        foreach (range(1, 6) as $i) {
            $user = $this->member(RoleName::Employee);
            $this->supervise($this->siti, $user);
            $this->rawOvertime($user, '2026-12-0'.$i.' 08:00', 2, RequestStatus::Pending);
            $this->rawLeave($user, '2026-12-1'.$i, '2026-12-1'.$i, 'sick', RequestStatus::Pending);
            $this->rawOvertime($this->eko, '2026-12-1'.$i.' 08:00', 2, RequestStatus::Approved, $this->siti);
            $this->member(RoleName::Approver);
            $this->rawOvertime($this->siti, '2026-12-2'.$i.' 08:00', 2, RequestStatus::Pending);
        }

        $this->assertSame($before, ['hana' => $count($this->hana), 'siti' => $count($this->siti), 'eko' => $count($this->eko)]);
        $this->assertSame(1 + 12, $this->numbers($this->siti)['waitingForMe']);
    }
}
