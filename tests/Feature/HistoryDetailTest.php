<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\History\Index;
use App\Livewire\History\Show;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReportData;
use Tests\TestCase;

/**
 * The read-only request page opened from history (Phase 12): for whoever sees the row there, without
 * decision buttons and without the evidence for those who may only view.
 */
class HistoryDetailTest extends TestCase
{
    use RefreshDatabase, SeedsReportData;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Evidence::DISK);
        $this->seedReportData();
    }

    private function url(string $name): string
    {
        $request = $this->requests[$name];

        return route('history.show', ['type' => $request instanceof LeaveRequest ? 'leave' : 'overtime', 'id' => $request->id]);
    }

    private function evidenceUrl(LeaveRequest|OvertimeRequest $request): string
    {
        return route('requests.evidence', ['type' => $request instanceof LeaveRequest ? 'leave' : 'overtime', 'id' => $request->id]);
    }

    private function withEvidence(User $user, string $date): LeaveRequest
    {
        RateLimiter::clear('requests:submit:'.$user->id);
        $pdf = UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");

        return app(LeaveRequests::class)->submit($user, [
            'category' => 'sick', 'starts_on' => $date, 'ends_on' => $date, 'reason' => self::REASON,
        ], $pdf);
    }

    public function test_hr_admin_views_an_employees_request_without_decision_buttons(): void
    {
        $this->actingAs($this->hana)->get($this->url('rina-leave'))
            ->assertOk()
            ->assertSee('Rina Employee')
            ->assertSee('Sales')
            ->assertSee(self::REASON)
            ->assertSee('You are viewing this request. You cannot decide it here.')
            ->assertDontSeeHtml('data-approve')
            ->assertDontSeeHtml('data-reject')
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSee('Start reviewing');

        // The decision of a decided request is shown, as on the other detail pages.
        $this->actingAs($this->hana)->get($this->url('eko-leave'))->assertOk()->assertSee('Siti Supervisor')->assertSeeHtml('data-decision');

        // Opening it takes no soft lock.
        $this->assertNull(LeaveRequest::query()->find($this->requests['rina-leave']->id)->review_locked_by);
    }

    public function test_a_supervisor_views_a_linked_approvers_request_they_cannot_decide(): void
    {
        $this->supervise($this->budi, $this->siti);

        $links = Livewire::actingAs($this->budi)->test(Index::class)->viewData('links');
        $this->assertSame($this->url('siti-overtime'), $links['overtime-'.$this->requests['siti-overtime']->id]);

        $this->actingAs($this->budi)->get($this->url('siti-overtime'))->assertOk()->assertSee('Siti Supervisor')->assertDontSeeHtml('data-approve');
    }

    public function test_anyone_who_does_not_see_the_row_gets_a_404(): void
    {
        // Siti supervises Eko only; Eko and Rina see only their own.
        $this->actingAs($this->siti)->get($this->url('rina-leave'))->assertNotFound();
        $this->actingAs($this->eko)->get($this->url('rina-leave'))->assertNotFound();
        $this->actingAs($this->rina)->get($this->url('eko-overtime'))->assertNotFound();
        $this->actingAs($this->hana)->get(route('history.show', ['type' => 'leave', 'id' => 999999]))->assertNotFound();

        Livewire::actingAs($this->siti)->test(Show::class, ['type' => 'leave', 'id' => (string) $this->requests['rina-leave']->id])->assertNotFound();

        // Their own request and a linked subordinate's open.
        $this->actingAs($this->eko)->get($this->url('eko-overtime'))->assertOk();
        $this->actingAs($this->siti)->get($this->url('eko-overtime'))->assertOk();

        // A removed link closes the page at once.
        DB::table('supervisor_user')->where('supervisor_id', $this->siti->id)->delete();
        $this->actingAs($this->siti)->get($this->url('eko-overtime'))->assertNotFound();
    }

    public function test_the_page_needs_history_access_by_url_and_by_livewire(): void
    {
        $nobody = User::factory()->twoFactorEnrolled()->create();
        $nobody->givePermissionTo(PermissionName::DashboardView);

        $this->actingAs($nobody)->get($this->url('rina-leave'))->assertForbidden();
        Livewire::actingAs($nobody)->test(Show::class, ['type' => 'leave', 'id' => (string) $this->requests['rina-leave']->id])->assertForbidden();

        // A page opened before the permission was revoked is refused on its next update.
        $component = Livewire::actingAs($this->hana)->test(Show::class, ['type' => 'leave', 'id' => (string) $this->requests['rina-leave']->id])->assertOk();
        Role::findByName(RoleName::HrAdmin->value)->revokePermissionTo([PermissionName::RequestsViewAll->value, PermissionName::RequestsSubmit->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $component->call('$refresh')->assertForbidden();
    }

    public function test_the_locked_properties_cannot_be_changed(): void
    {
        $component = Livewire::actingAs($this->eko)->test(Show::class, ['type' => 'overtime', 'id' => (string) $this->requests['eko-overtime']->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('requestId', $this->requests['rina-leave']->id);
    }

    public function test_viewers_cannot_download_evidence_but_the_requester_and_deciders_can(): void
    {
        $request = $this->withEvidence($this->rina, '2026-11-18');

        // Hana sees Rina's request read-only: no link, and the download itself is refused.
        $this->actingAs($this->hana)->get(route('history.show', ['type' => 'leave', 'id' => $request->id]))
            ->assertOk()
            ->assertSeeHtml('data-evidence-restricted')
            ->assertDontSeeHtml('data-evidence-link')
            ->assertDontSee('letter.pdf');
        $this->actingAs($this->hana)->get($this->evidenceUrl($request))->assertNotFound();

        // Rina and Budi (who decides her requests) may.
        $this->actingAs($this->rina)->get($this->evidenceUrl($request))->assertOk();
        $this->actingAs($this->budi)->get($this->evidenceUrl($request))->assertOk();
        $this->actingAs($this->budi)->get(route('history.show', ['type' => 'leave', 'id' => $request->id]))->assertSeeHtml('data-evidence-link');

        // A supervisor of an Approver sees the Approver's request but not the file; any HR Admin decides it and may.
        $this->supervise($this->budi, $this->siti);
        $sitis = $this->withEvidence($this->siti, '2026-11-19');

        $this->actingAs($this->budi)->get(route('history.show', ['type' => 'leave', 'id' => $sitis->id]))->assertOk()->assertSeeHtml('data-evidence-restricted');
        $this->actingAs($this->budi)->get($this->evidenceUrl($sitis))->assertNotFound();
        $this->actingAs($this->hana)->get($this->evidenceUrl($sitis))->assertOk();
    }
}
