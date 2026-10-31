<?php

namespace Tests\Feature;

use App\Actions\CancelRequest;
use App\Actions\SubmitLeaveRequest;
use App\Actions\SubmitOvertimeRequest;
use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\ShowLeave;
use App\Livewire\Requests\ShowOvertime;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\MakesRequests;
use Tests\Concerns\ReplaysLivewire;
use Tests\TestCase;

class RequestPagesTest extends TestCase
{
    use MakesRequests, RefreshDatabase, ReplaysLivewire;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->employee = $this->supervisedEmployee();
    }

    private function leave(string $date = '2026-06-16', ?User $user = null): LeaveRequest
    {
        return app(SubmitLeaveRequest::class)->handle($user ?? $this->employee, $this->leaveInput($date), null);
    }

    private function overtime(string $day = '2026-06-17'): OvertimeRequest
    {
        return app(SubmitOvertimeRequest::class)->handle($this->employee, $this->overtimeInput($day.'T18:00', $day.'T20:00'), null);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function roles(): array
    {
        return ['HR Admin' => [RoleName::HrAdmin], 'Approver' => [RoleName::Approver], 'Employee' => [RoleName::Employee]];
    }

    #[DataProvider('roles')]
    public function test_every_role_has_the_menu_and_the_page(RoleName $role): void
    {
        $user = $this->person($role);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('My requests')->assertSee(route('requests.index'), false);
        $this->actingAs($user)->get(route('requests.index'))->assertOk();
        $this->actingAs($user)->get(route('requests.leave.create'))->assertOk();
        $this->actingAs($user)->get(route('requests.overtime.create'))->assertOk();
    }

    public function test_the_list_shows_both_kinds_of_own_requests_only(): void
    {
        $this->leave();
        $this->overtime();
        $this->leave('2026-06-18', $this->supervisedEmployee());

        $page = Livewire::actingAs($this->employee)->test(Index::class);

        $page->assertSee('Annual leave')->assertSee('Overtime')->assertSee('16 Jun 2026')->assertDontSee('18 Jun 2026');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->leave();
        $this->overtime()->forceFill(['status' => RequestStatus::Cancelled])->save();

        Livewire::actingAs($this->employee)->test(Index::class)
            ->set('statusFilter', 'cancelled')
            ->assertSee('Overtime')
            ->assertDontSee('Annual leave')
            ->set('statusFilter', 'pending')
            ->assertSee('Annual leave')
            ->assertDontSee('2 hours');
    }

    public function test_an_unknown_filter_is_ignored(): void
    {
        $this->leave();

        Livewire::actingAs($this->employee)->test(Index::class)
            ->set('statusFilter', "x' or 1=1")
            ->assertSet('statusFilter', '')
            ->assertSee('Annual leave');
    }

    public function test_the_list_pages_through_the_union(): void
    {
        foreach (range(1, 16) as $day) {
            $this->overtime(CarbonImmutable::parse('2026-07-01')->addDays($day)->toDateString());
            $this->travel(1)->seconds();
        }

        $this->actingAs($this->employee)->get(route('requests.index'))->assertOk()->assertSee('Showing 1 to 15 of 16');
    }

    public function test_someone_blocked_sees_disabled_buttons_and_why(): void
    {
        $blocked = $this->person(RoleName::Employee);

        $this->actingAs($blocked)->get(route('requests.index'))
            ->assertSee('no active supervisor')
            ->assertSee('disabled', false)
            ->assertDontSee('href="'.route('requests.leave.create').'"', false);

        $this->actingAs($blocked)->get(route('requests.leave.create'))->assertSee('no active supervisor')->assertSee('disabled', false);
        $this->actingAs($blocked)->get(route('requests.overtime.create'))->assertSee('no active supervisor')->assertSee('disabled', false);
    }

    public function test_someone_eligible_sees_working_links(): void
    {
        $this->actingAs($this->employee)->get(route('requests.index'))
            ->assertDontSee('no active supervisor')
            ->assertSee(route('requests.leave.create'), false)
            ->assertSee(route('requests.overtime.create'), false);
    }

    public function test_the_form_keeps_what_was_typed_after_an_error(): void
    {
        $this->actingAs($this->employee)
            ->from(route('requests.leave.create'))
            ->post(route('requests.leave.store'), $this->leaveInput('2026-06-20', '2026-06-21'))
            ->assertRedirect(route('requests.leave.create'));

        $this->get(route('requests.leave.create'))
            ->assertSee('no working days')
            ->assertSee('value="2026-06-20"', false)
            ->assertSee(self::VALID_REASON);
    }

    public function test_the_overtime_inputs_count_their_hour_steps_from_a_whole_hour(): void
    {
        $this->actingAs($this->employee)->get(route('requests.overtime.create'))
            ->assertSee('min="2026-06-15T00:00"', false);
    }

    public function test_the_detail_pages_show_the_request(): void
    {
        $this->actingAs($this->employee)->get(route('requests.leave.show', $this->leave()->id))
            ->assertOk()->assertSee('16 Jun 2026')->assertSee(self::VALID_REASON)->assertSee('Cancel request');

        $this->actingAs($this->employee)->get(route('requests.overtime.show', $this->overtime()->id))
            ->assertOk()->assertSee('17 Jun 2026, 18:00 WIB')->assertSee('Cancel request');
    }

    public function test_another_person_cannot_open_a_request_and_an_unknown_one_looks_the_same(): void
    {
        $leave = $this->leave();
        $overtime = $this->overtime();

        foreach ([RoleName::Employee, RoleName::Approver, RoleName::HrAdmin] as $role) {
            $user = $this->person($role);

            $this->actingAs($user)->get(route('requests.leave.show', $leave->id))->assertForbidden();
            $this->actingAs($user)->get(route('requests.overtime.show', $overtime->id))->assertForbidden();
            $this->actingAs($user)->get(route('requests.leave.show', 999_999))->assertForbidden();
        }
    }

    public function test_a_guest_cannot_reach_any_page(): void
    {
        foreach (['requests.index', 'requests.leave.create', 'requests.overtime.create', 'holidays.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $this->post(route('requests.leave.store'))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_permission_is_refused_everywhere(): void
    {
        $this->employee->roles()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->employee->fresh();

        $this->actingAs($user)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($user)->get(route('requests.leave.create'))->assertForbidden();
        $this->actingAs($user)->post(route('requests.leave.store'))->assertForbidden();
        $this->actingAs($user)->post(route('requests.overtime.store'))->assertForbidden();
        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
    }

    public function test_every_route_carries_its_permission(): void
    {
        foreach (['requests.index', 'requests.leave.create', 'requests.leave.store', 'requests.leave.show', 'requests.overtime.create', 'requests.overtime.store', 'requests.overtime.show'] as $name) {
            $this->assertContains('can:'.PermissionName::RequestsCreate->value, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $this->assertContains('can:'.PermissionName::HolidaysManage->value, Route::getRoutes()->getByName('holidays.index')->gatherMiddleware());
    }

    public function test_submitting_is_rate_limited(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->actingAs($this->employee)->post(route('requests.overtime.store'), []);
        }

        $this->actingAs($this->employee)->post(route('requests.overtime.store'), [])->assertTooManyRequests();
    }

    public function test_the_owner_can_cancel_and_the_row_stays(): void
    {
        $leave = $this->leave();

        Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $leave->id])
            ->call('cancel')
            ->assertSet('notice', '')
            ->assertSee('Cancelled')
            ->assertDontSee("name: 'request-cancel'", false);

        $this->assertSame(RequestStatus::Cancelled, $leave->fresh()->status);
        $this->assertNotNull($leave->fresh()->cancelled_at);
        $this->assertSame(1, $leave->allocations()->count());

        $entry = AuditLog::where('event', AuditEvent::LeaveRequestCancelled->value)->sole();

        $this->assertSame($this->employee->id, $entry->actor_id);
    }

    public function test_overtime_can_be_cancelled(): void
    {
        $overtime = $this->overtime();

        Livewire::actingAs($this->employee)->test(ShowOvertime::class, ['request' => (string) $overtime->id])->call('cancel');

        $this->assertSame(RequestStatus::Cancelled, $overtime->fresh()->status);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::OvertimeRequestCancelled->value)->count());
    }

    public function test_a_second_cancel_changes_nothing_and_says_so(): void
    {
        $leave = $this->leave();
        $page = Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $leave->id])->call('cancel');

        $at = $leave->fresh()->cancelled_at;
        $this->travel(5)->minutes();

        $page->call('cancel')->assertSet('notice', 'This request is no longer pending, so it cannot be cancelled.');

        $this->assertEquals($at, $leave->fresh()->cancelled_at);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::LeaveRequestCancelled->value)->count());
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function decidedStatuses(): array
    {
        return ['approved' => [RequestStatus::Approved], 'rejected' => [RequestStatus::Rejected]];
    }

    #[DataProvider('decidedStatuses')]
    public function test_a_decided_request_cannot_be_cancelled(RequestStatus $status): void
    {
        $leave = $this->leave();
        $leave->forceFill(['status' => $status])->save();

        $page = Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $leave->id]);

        $page->assertDontSee("name: 'request-cancel'", false)->call('cancel')->assertSet('notice', 'This request is no longer pending, so it cannot be cancelled.');

        $this->assertSame($status, $leave->fresh()->status);
    }

    public function test_only_the_requester_can_cancel_even_as_hr_admin(): void
    {
        $leave = $this->leave();

        $this->expectException(AuthorizationException::class);

        app(CancelRequest::class)->handle($leave, $this->person(RoleName::HrAdmin));
    }

    public function test_a_replayed_snapshot_cannot_cancel_for_someone_else(): void
    {
        $leave = $this->leave();
        $snapshot = $this->snapshotOf($this->employee, route('requests.leave.show', $leave->id));

        foreach ([RoleName::Employee, RoleName::Approver, RoleName::HrAdmin] as $role) {
            $this->actingAs($this->person($role));

            $this->callLivewire($snapshot, 'cancel')->assertForbidden();
        }

        $this->assertSame(RequestStatus::Pending, $leave->fresh()->status);
    }

    public function test_the_request_id_is_locked(): void
    {
        $leave = $this->leave();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->employee)->test(ShowLeave::class, ['request' => (string) $leave->id])->set('requestId', 1);
    }

    public function test_a_forged_array_for_the_filter_is_refused(): void
    {
        $snapshot = $this->snapshotOf($this->employee, route('requests.index'));

        $this->callLivewire($snapshot, null, [], ['statusFilter' => ['x']])->assertStatus(422);
    }

    public function test_the_cancel_button_opens_the_dialog_by_its_real_name(): void
    {
        $this->actingAs($this->employee)->get(route('requests.leave.show', $this->leave()->id))
            ->assertSee("\$dispatch('open-modal', { name: 'request-cancel' })", false)
            ->assertSee('request-cancel-title', false);
    }
}
