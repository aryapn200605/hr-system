<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\Show;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class RequestPagesTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Visiting family in Bandung.';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        Storage::fake(Evidence::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->employee = $this->supervisedMember();
    }

    private function leave(User $user, string $start = '2026-11-09', string $end = '2026-11-10'): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, ['category' => 'annual', 'starts_on' => $start, 'ends_on' => $end, 'reason' => self::REASON]);
    }

    public function test_every_role_reaches_my_requests_and_sees_the_menu_entry(): void
    {
        foreach ([RoleName::Employee, RoleName::Approver, RoleName::HrAdmin] as $role) {
            $user = $this->member($role);

            $this->actingAs($user)->get(route('requests.index'))->assertOk()->assertSee(__('My requests'));
            $this->actingAs($user)->get(route('dashboard'))->assertSee(route('requests.index'));
        }
    }

    public function test_submitting_leave_through_the_page(): void
    {
        Livewire::actingAs($this->employee)->test(Index::class)
            ->call('openLeave')
            ->assertSet('category', 'annual')
            ->assertSet('starts_on', '2026-11-04')
            ->set('starts_on', '2026-11-09')
            ->set('ends_on', '2026-11-13')
            ->set('reason', self::REASON)
            ->set('evidence', UploadedFile::fake()->image('note.png', 10, 10))
            ->call('submitLeave')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Leave request submitted for 5 working days.')
            ->assertDispatched('close-modal', name: 'leave-form')
            ->assertSet('reason', '')
            ->assertSee('Pending approval');

        $request = LeaveRequest::query()->sole();
        $this->assertSame(5, $request->working_days);
        $this->assertSame('image/png', $request->evidence_mime);
    }

    public function test_choosing_another_file_clears_the_old_evidence_error(): void
    {
        Livewire::actingAs($this->employee)->test(Index::class)
            ->call('openLeave')
            ->set('starts_on', '2026-11-09')
            ->set('ends_on', '2026-11-09')
            ->set('reason', self::REASON)
            ->set('evidence', UploadedFile::fake()->createWithContent('fake.pdf', "plain text\n"))
            ->call('submitLeave')
            ->assertHasErrors(['evidence'])
            ->set('evidence', UploadedFile::fake()->image('note.png', 10, 10))
            ->assertHasNoErrors(['evidence'])
            ->assertDontSee('The evidence must be a PDF, JPG or PNG file.');
    }

    public function test_service_errors_land_on_the_form_fields(): void
    {
        Livewire::actingAs($this->employee)->test(Index::class)
            ->call('openLeave')
            ->set('starts_on', '2026-11-03')
            ->set('reason', 'short')
            ->call('submitLeave')
            ->assertHasErrors(['starts_on', 'reason']);

        Livewire::actingAs($this->employee)->test(Index::class)
            ->call('openLeave')
            ->set('starts_on', '2026-11-04')
            ->set('ends_on', '2026-11-20')
            ->set('reason', self::REASON)
            ->call('submitLeave')
            ->assertHasErrors(['request'])
            ->assertSee('13 working days requested, 12 available');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_submitting_overtime_through_the_page_maps_errors_to_its_fields(): void
    {
        $component = Livewire::actingAs($this->employee)->test(Index::class)
            ->call('openOvertime')
            ->set('start_date', '2026-11-04')
            ->set('start_hour', '20:00')
            ->set('end_date', '2026-11-05')
            ->set('end_hour', '09:00')
            ->set('overtime_reason', self::REASON)
            ->call('submitOvertime')
            ->assertHasErrors(['end_date']);

        // A forged half hour is refused by the server.
        $component->set('end_hour', '02:30')->call('submitOvertime')->assertHasErrors(['end_date']);

        $component->set('end_hour', '02:00')->call('submitOvertime')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Overtime request submitted for 6 hours.')
            ->assertSet('type', 'overtime');

        $this->assertSame(6, OvertimeRequest::query()->sole()->hours);
    }

    public function test_without_an_approval_path_the_buttons_are_disabled_with_the_reason(): void
    {
        $alone = $this->member(RoleName::Employee);

        $html = Livewire::actingAs($alone)->test(Index::class)
            ->assertSeeHtml('data-no-approval-path')
            ->assertSee('You have no active supervisor yet, so you cannot submit requests. Ask HR to assign one.')
            ->html();

        foreach (['data-new-leave', 'data-new-overtime'] as $marker) {
            $this->assertMatchesRegularExpression('/<button(?=[^>]*\b'.$marker.'\b)(?=[^>]*\sdisabled="disabled")[^>]*>/', $html);
        }

        // A forged call still meets the server check.
        Livewire::actingAs($alone)->test(Index::class)
            ->set('starts_on', '2026-11-09')->set('ends_on', '2026-11-09')->set('category', 'annual')->set('reason', self::REASON)
            ->call('submitLeave')
            ->assertHasErrors(['request']);

        $this->assertSame(0, LeaveRequest::query()->count());

        Livewire::actingAs($this->employee)->test(Index::class)->assertDontSeeHtml('data-no-approval-path');
    }

    public function test_the_list_shows_only_my_requests_with_filters(): void
    {
        $mine = $this->leave($this->employee);
        $other = $this->supervisedMember(RoleName::Employee, ['name' => 'Other Person']);
        $this->leave($other, '2026-11-16', '2026-11-16');
        app(OvertimeRequests::class)->submit($this->employee, ['start_at' => '2026-11-04 18:00', 'end_at' => '2026-11-04 20:00', 'reason' => self::REASON]);

        Livewire::actingAs($this->employee)->test(Index::class)
            ->assertSee(route('requests.show', ['type' => 'leave', 'id' => $mine->id]))
            ->assertDontSee('16 Nov 2026')
            ->set('statusFilter', RequestStatus::Cancelled->value)
            ->assertDontSee(route('requests.show', ['type' => 'leave', 'id' => $mine->id]))
            ->set('statusFilter', 'bogus')
            ->assertSee(route('requests.show', ['type' => 'leave', 'id' => $mine->id]))
            ->set('type', 'overtime')
            ->assertSee('18:00 WIB')
            ->set('type', 'payroll')
            ->assertSee(route('requests.show', ['type' => 'leave', 'id' => $mine->id]));
    }

    public function test_the_balance_card_shows_pending_and_available_days(): void
    {
        $this->leave($this->employee, '2026-11-09', '2026-11-13');

        Livewire::actingAs($this->employee)->test(Index::class)
            ->assertSeeInOrder(['Current period', '4 Nov 2026', '3 Nov 2027', 'In pending requests', '5 days', 'Available', '7 days'])
            ->assertSeeInOrder(['Next period', '4 Nov 2027', 'Projected']);
    }

    public function test_forged_properties_are_refused(): void
    {
        Livewire::actingAs($this->employee)->test(Index::class)->set('reason', ['x'])->assertStatus(422);
        Livewire::actingAs($this->employee)->test(Index::class)->set('evidence', 'not-a-file')->assertStatus(422);
    }

    public function test_the_detail_page_and_cancel(): void
    {
        $request = $this->leave($this->employee);

        $this->actingAs($this->employee)->get(route('requests.show', ['type' => 'leave', 'id' => $request->id]))
            ->assertOk()
            ->assertSee(self::REASON)
            ->assertSee('Annual leave')
            ->assertSee('Leave balance used per period');

        Livewire::actingAs($this->employee)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->call('openCancel')
            ->assertDispatched('open-modal', name: 'request-cancel')
            ->call('cancel')
            ->assertDispatched('toast', message: 'The request was cancelled.')
            ->assertSee('Cancelled')
            ->assertDontSeeHtml('data-cancel-request');

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_a_second_cancel_from_a_stale_page_shows_a_notice(): void
    {
        $request = $this->leave($this->employee);
        $page = Livewire::actingAs($this->employee)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id]);

        app(LeaveRequests::class)->cancel($this->employee, $request->id);

        $page->call('cancel')
            ->assertSet('notice', 'This request is no longer pending: it is Cancelled.')
            ->assertNotDispatched('toast');
    }

    public function test_a_stale_page_gets_the_reason_instead_of_the_cancel_dialog(): void
    {
        $request = $this->leave($this->employee);
        $page = Livewire::actingAs($this->employee)->test(Show::class, ['type' => 'leave', 'id' => (string) $request->id])
            ->assertSeeHtml('data-cancel-request');

        LeaveRequest::query()->update(['status' => RequestStatus::Approved->value]);

        $page->call('openCancel')
            ->assertNotDispatched('open-modal')
            ->assertSet('notice', 'This request is no longer pending: it is Approved.')
            ->assertDontSeeHtml('data-cancel-request');
    }

    public function test_someone_elses_request_is_a_404_by_url_and_by_forged_update(): void
    {
        $request = $this->leave($this->employee);
        $other = $this->supervisedMember();

        $this->actingAs($other)->get(route('requests.show', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
        $this->actingAs($this->member(RoleName::HrAdmin))->get(route('requests.show', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
        $this->actingAs($this->employee)->get(route('requests.show', ['type' => 'overtime', 'id' => $request->id]))->assertNotFound();
        $this->actingAs($this->employee)->get('/requests/payroll/'.$request->id)->assertNotFound();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $mine = $this->leave($other, '2026-11-16', '2026-11-16');
        Livewire::actingAs($other)->test(Show::class, ['type' => 'leave', 'id' => (string) $mine->id])->set('requestId', $request->id);
    }

    public function test_the_overtime_detail_page(): void
    {
        $request = app(OvertimeRequests::class)->submit($this->employee, ['start_at' => '2026-11-04 20:00', 'end_at' => '2026-11-05 01:00', 'reason' => self::REASON]);

        $this->actingAs($this->employee)->get(route('requests.show', ['type' => 'overtime', 'id' => $request->id]))
            ->assertOk()
            ->assertSee('Overtime request')
            ->assertSeeInOrder(['Start', '4 Nov 2026, 20:00 WIB', 'End', '5 Nov 2026, 01:00 WIB', 'Hours', '5']);
    }

    public function test_the_pages_are_in_indonesian(): void
    {
        $this->employee->forceFill(['locale' => 'id'])->save();

        $this->actingAs($this->employee)->get(route('requests.index'))
            ->assertSee('Pengajuan Saya')
            ->assertSee('Ajukan izin')
            ->assertSee('Saldo cuti tahunan');
    }

    public function test_the_routes_check_the_permission_themselves(): void
    {
        foreach (['requests.index', 'requests.show', 'requests.evidence'] as $name) {
            $this->assertContains('can:requests.submit', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $this->assertContains('can:holidays.manage', app('router')->getRoutes()->getByName('holidays.index')->gatherMiddleware());
    }

    public function test_a_user_without_the_permission_is_refused_everywhere(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();

        $this->actingAs($nobody)->get(route('requests.index'))->assertForbidden();
        Livewire::actingAs($nobody)->test(Index::class)->assertForbidden();
        Livewire::actingAs($nobody)->test(Show::class, ['type' => 'leave', 'id' => '1'])->assertForbidden();
    }
}
