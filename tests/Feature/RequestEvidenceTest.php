<?php

namespace Tests\Feature;

use App\Actions\SubmitLeaveRequest;
use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\RequestEvidence;
use App\Support\SupervisorRelations;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesRequests;
use Tests\TestCase;

class RequestEvidenceTest extends TestCase
{
    use MakesRequests, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    private User $admin;

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('evidence');
        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));

        $this->admin = $this->person(RoleName::HrAdmin);
        $this->supervisor = $this->person(RoleName::Approver);
        $this->employee = $this->supervisedEmployee($this->supervisor);
    }

    /**
     * A real upload. The fake one reports a type from its name, which would prove nothing here.
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'medical-letter-budi.pdf'): UploadedFile
    {
        return $this->upload($name, self::PDF);
    }

    private function leaveWithEvidence(?User $user = null, string $date = '2026-06-15'): LeaveRequest
    {
        return app(SubmitLeaveRequest::class)->handle($user ?? $this->employee, $this->leaveInput($date), $this->pdf());
    }

    private function url(LeaveRequest $request): string
    {
        return route('requests.leave.evidence', $request->id);
    }

    public function test_a_file_is_stored_privately_under_a_random_name(): void
    {
        $this->actingAs($this->employee)->post(route('requests.leave.store'), $this->leaveInput('2026-06-15') + ['evidence' => $this->pdf()])
            ->assertSessionHasNoErrors();

        $request = LeaveRequest::sole();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.pdf$/', $request->evidence_path);
        $this->assertSame('application/pdf', $request->evidence_mime);
        Storage::disk('evidence')->assertExists($request->evidence_path);
    }

    public function test_the_evidence_disk_is_outside_the_public_folder_and_never_served(): void
    {
        $disk = config('filesystems.disks.evidence');

        $this->assertStringStartsWith(storage_path('app/private'), $disk['root']);
        $this->assertArrayNotHasKey('serve', $disk);
        $this->assertArrayNotHasKey('url', $disk);
    }

    public function test_overtime_takes_evidence_too(): void
    {
        $this->actingAs($this->employee)->post(route('requests.overtime.store'), $this->overtimeInput('2026-06-15T18:00', '2026-06-15T20:00') + ['evidence' => $this->pdf()])
            ->assertSessionHasNoErrors();

        $request = OvertimeRequest::sole();

        $this->actingAs($this->employee)->get(route('requests.overtime.evidence', $request->id))->assertOk();
        $this->actingAs($this->person(RoleName::Employee))->get(route('requests.overtime.evidence', $request->id))->assertForbidden();
    }

    public function test_the_upload_is_audited_without_the_file_name(): void
    {
        $request = $this->leaveWithEvidence();

        $entry = AuditLog::where('event', AuditEvent::RequestEvidenceUploaded->value)->sole();

        $this->assertSame($request->id, $entry->subject_id);
        $this->assertSame('application/pdf', $entry->metadata['mime']);
        $this->assertStringNotContainsString('medical', json_encode(AuditLog::all()));
        $this->assertStringNotContainsString('medical', json_encode(LeaveRequest::all()->makeVisible('evidence_path')));
    }

    public function test_jpg_and_png_are_accepted_by_their_content(): void
    {
        $jpg = $this->upload('a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
        $png = $this->upload('b.png', UploadedFile::fake()->image('b.png')->getContent());

        app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput('2026-06-15'), $jpg);
        app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput('2026-06-16'), $png);

        $this->assertSame(['image/jpeg', 'image/png'], LeaveRequest::orderBy('id')->pluck('evidence_mime')->all());
        $this->assertStringEndsWith('.png', LeaveRequest::latest('id')->first()->evidence_path);
    }

    public function test_the_extension_comes_from_the_content_and_not_the_client(): void
    {
        $png = $this->upload('looks-like.pdf', UploadedFile::fake()->image('x.png')->getContent());

        $request = app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput('2026-06-15'), $png);

        $this->assertStringEndsWith('.png', $request->evidence_path);
    }

    public function test_content_that_only_claims_to_be_allowed_is_refused(): void
    {
        foreach (['script.pdf' => '<?php echo 1;', 'page.png' => '<html><script>alert(1)</script></html>', 'tool.jpg' => "MZ\x90\x00\x03"] as $name => $content) {
            $file = $this->upload($name, $content);

            $this->actingAs($this->employee)->post(route('requests.leave.store'), $this->leaveInput('2026-06-15') + ['evidence' => $file])
                ->assertSessionHasErrors(['evidence' => RequestEvidence::TYPE_MESSAGE]);
        }

        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame([], Storage::disk('evidence')->allFiles());
    }

    public function test_the_size_limit_comes_from_the_configuration(): void
    {
        config(['requests.evidence_max_kb' => 1]);

        $file = $this->upload('big.pdf', self::PDF.str_repeat('x', 2048));

        $this->actingAs($this->employee)->post(route('requests.leave.store'), $this->leaveInput('2026-06-15') + ['evidence' => $file])
            ->assertSessionHasErrors('evidence');
    }

    public function test_the_default_limit_is_five_megabytes(): void
    {
        $this->assertSame(5120, config('requests.evidence_max_kb'));
    }

    public function test_a_refused_request_leaves_no_file_behind(): void
    {
        $this->leaveWithEvidence();

        $this->actingAs($this->employee)->post(route('requests.leave.store'), $this->leaveInput('2026-06-15') + ['evidence' => $this->pdf()])
            ->assertSessionHasErrors('start_date');

        $this->assertCount(1, Storage::disk('evidence')->allFiles());
    }

    public function test_the_owner_can_download_as_an_attachment_with_safe_headers(): void
    {
        $response = $this->actingAs($this->employee)->get($this->url($this->leaveWithEvidence()));

        $response->assertOk();

        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertMatchesRegularExpression('/evidence-\d+\.pdf/', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_supervisor_of_the_requester_can_download(): void
    {
        $this->actingAs($this->supervisor)->get($this->url($this->leaveWithEvidence()))->assertOk();
    }

    public function test_an_hr_admin_can_download(): void
    {
        $this->actingAs($this->admin)->get($this->url($this->leaveWithEvidence()))->assertOk();
    }

    public function test_an_unrelated_approver_and_employee_cannot_download(): void
    {
        $url = $this->url($this->leaveWithEvidence());

        $this->actingAs($this->person(RoleName::Approver))->get($url)->assertForbidden();
        $this->actingAs($this->person(RoleName::Employee))->get($url)->assertForbidden();
    }

    public function test_a_supervisor_who_lost_the_role_cannot_download(): void
    {
        $url = $this->url($this->leaveWithEvidence());

        SupervisorRelations::assign($this->employee, $this->person(RoleName::Approver));
        $this->supervisor->changeRole(RoleName::Employee, $this->admin);

        $this->actingAs($this->supervisor->fresh())->get($url)->assertForbidden();
    }

    public function test_when_the_requester_is_an_approver_only_hr_admin_can_download(): void
    {
        $requester = $this->person(RoleName::Approver);
        SupervisorRelations::assign($requester, $this->supervisor);

        $url = $this->url($this->leaveWithEvidence($requester));

        $this->actingAs($this->supervisor)->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($requester)->get($url)->assertOk();
    }

    public function test_a_supervisor_of_an_hr_admin_can_download(): void
    {
        SupervisorRelations::assign($this->admin, $this->supervisor);

        $this->actingAs($this->supervisor)->get($this->url($this->leaveWithEvidence($this->admin)))->assertOk();
    }

    public function test_an_unknown_id_looks_the_same_as_a_forbidden_one(): void
    {
        $this->actingAs($this->employee)->get(route('requests.leave.evidence', 999_999))->assertForbidden();
        $this->actingAs($this->employee)->get(route('requests.overtime.evidence', 999_999))->assertForbidden();
    }

    public function test_a_request_without_evidence_says_so_only_to_those_allowed(): void
    {
        $request = app(SubmitLeaveRequest::class)->handle($this->employee, $this->leaveInput('2026-06-15'), null);

        $this->actingAs($this->employee)->get($this->url($request))->assertNotFound();
        $this->actingAs($this->person(RoleName::Employee))->get($this->url($request))->assertForbidden();
    }

    public function test_a_lost_file_is_a_404_and_not_an_error(): void
    {
        $request = $this->leaveWithEvidence();

        Storage::disk('evidence')->delete($request->evidence_path);

        $this->actingAs($this->employee)->get($this->url($request))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get($this->url($this->leaveWithEvidence()))->assertRedirect(route('login'));
    }

    public function test_the_path_never_reaches_the_page(): void
    {
        $request = $this->leaveWithEvidence();

        $this->actingAs($this->employee)->get(route('requests.leave.show', $request->id))->assertDontSee($request->evidence_path, false);
        $this->actingAs($this->employee)->get(route('requests.index'))->assertDontSee($request->evidence_path, false);
        $this->assertSame(1, DB::table('leave_requests')->whereNotNull('evidence_path')->count());
    }
}
