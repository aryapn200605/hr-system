<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

class RequestEvidenceTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Doctor letter attached here.';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        Storage::fake(Evidence::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->employee = $this->supervisedMember();
    }

    private function pdf(string $name = 'letter.pdf', int $padKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n".str_repeat('0', $padKb * 1024)."\n%%EOF\n");
    }

    private function leave(?UploadedFile $file, string $date = '2026-11-09', ?User $user = null): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user ?? $this->employee, [
            'category' => 'sick', 'starts_on' => $date, 'ends_on' => $date, 'reason' => self::REASON,
        ], $file);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The input was accepted.');
    }

    public function test_a_pdf_is_stored_privately_under_a_random_name(): void
    {
        $request = $this->leave($this->pdf('Surat dokter.pdf'));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.pdf$/', $request->evidence_path);
        $this->assertSame('Surat dokter.pdf', $request->evidence_name);
        $this->assertSame('application/pdf', $request->evidence_mime);
        Storage::disk(Evidence::DISK)->assertExists($request->evidence_path);

        $audit = AuditLog::query()->where('event', AuditEvent::RequestEvidenceUploaded->value)->sole();
        $this->assertEquals(['mime' => 'application/pdf', 'size' => $request->evidence_size], $audit->metadata);
    }

    public function test_the_disk_is_private_and_not_served(): void
    {
        $this->assertSame(storage_path('app/private/evidence'), config('filesystems.disks.evidence.root'));
        $this->assertFalse(config('filesystems.disks.evidence.serve'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.evidence'));
    }

    public function test_png_and_jpg_are_accepted_and_named_by_their_real_type(): void
    {
        $jpg = $this->leave(UploadedFile::fake()->image('photo.jpg', 20, 20), '2026-11-09');
        $this->assertSame('image/jpeg', $jpg->evidence_mime);
        $this->assertStringEndsWith('.jpg', $jpg->evidence_path);

        $real = UploadedFile::fake()->image('scan.png', 20, 20);
        $request = $this->leave($real, '2026-11-10');
        $this->assertSame('image/png', $request->evidence_mime);
        $this->assertStringEndsWith('.png', $request->evidence_path);
    }

    public function test_a_file_whose_content_is_not_an_allowed_type_is_refused_whatever_its_name(): void
    {
        $spoofed = UploadedFile::fake()->createWithContent('letter.pdf', "<?php echo 'hello';\n");

        $errors = $this->errorsOf(fn () => $this->leave($spoofed));

        $this->assertSame(['The evidence must be a PDF, JPG or PNG file.'], $errors['evidence']);
        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame([], Storage::disk(Evidence::DISK)->allFiles());
    }

    public function test_a_file_over_the_limit_is_refused_and_the_limit_is_configurable(): void
    {
        config(['requests.evidence_max_kb' => 10]);

        $errors = $this->errorsOf(fn () => $this->leave($this->pdf(padKb: 11)));
        $this->assertSame(['The evidence may be at most 0.1 MB.'], $errors['evidence']);

        config(['requests.evidence_max_kb' => 5120]);
        $this->assertNotNull($this->leave($this->pdf(padKb: 11))->evidence_path);
    }

    public function test_the_size_message_names_the_default_limit(): void
    {
        $this->assertSame('5', app(Evidence::class)->maxMegabytes());
    }

    public function test_a_stored_file_is_removed_when_the_request_is_refused(): void
    {
        $this->leave(null, '2026-11-09');

        // Validated and stored first, then the overlap refuses it inside the transaction.
        $this->errorsOf(fn () => $this->leave($this->pdf(), '2026-11-09'));

        $this->assertSame([], Storage::disk(Evidence::DISK)->allFiles());
    }

    public function test_the_download_name_loses_path_and_control_characters(): void
    {
        $request = $this->leave($this->pdf("..\\..\\evil\"\x01name.png"));

        $this->assertSame('evilname.pdf', $request->evidence_name);
    }

    public function test_overtime_takes_evidence_too(): void
    {
        $request = app(OvertimeRequests::class)->submit($this->employee, [
            'start_at' => '2026-11-04 18:00', 'end_at' => '2026-11-04 20:00', 'reason' => self::REASON,
        ], $this->pdf());

        Storage::disk(Evidence::DISK)->assertExists($request->evidence_path);
    }

    // --- Download ---------------------------------------------------------------------------------

    public function test_the_requester_downloads_the_file_with_safe_headers(): void
    {
        $request = $this->leave($this->pdf('letter.pdf'));

        $response = $this->actingAs($this->employee)->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('letter.pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_overtime_evidence_downloads_through_its_own_type(): void
    {
        $request = app(OvertimeRequests::class)->submit($this->employee, [
            'start_at' => '2026-11-04 18:00', 'end_at' => '2026-11-04 20:00', 'reason' => self::REASON,
        ], $this->pdf());

        $this->actingAs($this->employee)->get(route('requests.evidence', ['type' => 'overtime', 'id' => $request->id]))->assertOk();
        // The same ID under the other type is another table.
        $this->actingAs($this->employee)->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
    }

    public function test_anyone_else_gets_a_404(): void
    {
        $request = $this->leave($this->pdf());
        $url = route('requests.evidence', ['type' => 'leave', 'id' => $request->id]);

        $this->actingAs($this->supervisedMember())->get($url)->assertNotFound();
        $this->actingAs($this->member(RoleName::HrAdmin))->get($url)->assertNotFound();
        $this->actingAs($this->member(RoleName::Approver))->get($url)->assertNotFound();
    }

    public function test_a_request_without_evidence_or_an_unknown_id_is_a_404(): void
    {
        $request = $this->leave(null);

        $this->actingAs($this->employee)->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertNotFound();
        $this->actingAs($this->employee)->get(route('requests.evidence', ['type' => 'leave', 'id' => 999]))->assertNotFound();
        $this->actingAs($this->employee)->get('/requests/payroll/1/evidence')->assertNotFound();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $request = $this->leave($this->pdf());

        $this->get(route('requests.evidence', ['type' => 'leave', 'id' => $request->id]))->assertRedirect(route('login'));
    }

    public function test_the_route_needs_a_full_login(): void
    {
        $route = app('router')->getRoutes()->getByName('requests.evidence');

        $this->assertContains('fully.authenticated', $route->gatherMiddleware());
    }

    public function test_no_disk_serves_files_by_url(): void
    {
        // The local disk's root holds the evidence folder; Laravel's signed file routes stay off (Phase 14).
        $this->assertFalse(app('router')->has('storage.local'));
        $this->assertFalse(app('router')->has('storage.local.upload'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(config('filesystems.disks.'.Evidence::DISK.'.serve'));

        $this->actingAs($this->member(RoleName::HrAdmin))->get('/storage/evidence/anything.pdf')->assertNotFound();
    }
}
