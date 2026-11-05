<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RequestStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Approvals\Show as ApprovalShow;
use App\Livewire\Requests\Index as RequestIndex;
use App\Mail\RequestDecidedMail;
use App\Mail\RequestSubmittedMail;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\Deciders;
use App\Services\Requests\Decisions;
use App\Services\Requests\Evidence;
use App\Services\Requests\LeaveRequests;
use App\Services\Requests\OvertimeRequests;
use App\Services\Requests\RequestNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRequesters;
use Tests\TestCase;

/**
 * Phase 13: emails to the deciders of a new request and to the requester of a decided one. Content without
 * reason, note or evidence, the recipient's language, links on APP_URL, delivery after the commit, and
 * failures that never undo the business action, are audited and are not reported as success.
 * Races are in RequestConcurrencyTest and ApprovalConcurrencyTest.
 */
class RequestNotificationTest extends TestCase
{
    use CreatesRequesters, RefreshDatabase;

    private const REASON = 'Private family matter, please keep quiet.';

    private const REJECTION = 'Too many people away that week.';

    private const NOTE = 'Enjoy the quiet days off.';

    private User $supervisor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRequestData();
        config(['app.url' => 'https://hr.example.test']);
        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->supervisor = $this->member(RoleName::Approver, ['name' => 'Siti Supervisor', 'email' => 'siti@example.test']);
        $this->employee = $this->member(RoleName::Employee, ['name' => 'Budi Employee', 'email' => 'budi@example.test']);
        $this->supervise($this->supervisor, $this->employee);
    }

    private function leave(User $user, string $start = '2026-12-07', string $end = '2026-12-08', mixed $evidence = null): LeaveRequest
    {
        return app(LeaveRequests::class)->submit($user, [
            'category' => 'annual',
            'starts_on' => $start,
            'ends_on' => $end,
            'reason' => self::REASON,
        ], $evidence);
    }

    private function overtime(User $user, string $start = '2026-12-07 22:00', string $end = '2026-12-08 01:00'): OvertimeRequest
    {
        return app(OvertimeRequests::class)->submit($user, [
            'start_at' => $start,
            'end_at' => $end,
            'reason' => self::REASON,
        ]);
    }

    /**
     * Make the real SMTP transport fail: nothing listens on this port.
     */
    private function breakTransport(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
    }

    private function failureAudits(): Collection
    {
        return AuditLog::query()->where('event', AuditEvent::RequestNotificationFailed->value)->orderBy('id')->get();
    }

    /**
     * @return list<string>
     */
    private function recipients(string $mailable): array
    {
        return Mail::sent($mailable)->map(fn ($mail) => $mail->to[0]['address'])->sort()->values()->all();
    }

    public function test_an_employee_request_emails_each_active_linked_supervisor_only(): void
    {
        Mail::fake();
        $second = $this->member(RoleName::Approver, ['email' => 'second@example.test']);
        $this->supervise($second, $this->employee);
        $suspended = $this->member(RoleName::Approver, ['email' => 'suspended@example.test']);
        $this->supervise($suspended, $this->employee);
        $suspended->forceFill(['status' => UserStatus::Suspended])->saveQuietly();
        $this->member(RoleName::Approver, ['email' => 'unlinked@example.test']);
        $this->member(RoleName::HrAdmin, ['email' => 'hr@example.test']);

        $request = $this->leave($this->employee);

        $this->assertSame(['second@example.test', 'siti@example.test'], $this->recipients(RequestSubmittedMail::class));
        Mail::assertNotSent(RequestDecidedMail::class);
        $this->assertSame(0, app(RequestNotifications::class)->failures($request));
    }

    public function test_finding_the_deciders_reads_only_the_linked_supervisors(): void
    {
        $deciders = app(Deciders::class);
        $this->employee->load('roles');
        $count = function () use ($deciders) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $found = $deciders->of($this->employee);
            DB::disableQueryLog();

            return [$found->pluck('id')->all(), count(DB::getQueryLog())];
        };

        // The first call warms the permission cache.
        $count();
        [$before, $queries] = $count();
        foreach (range(1, 4) as $i) {
            $this->member(RoleName::Approver);
            $this->member(RoleName::HrAdmin);
        }
        [$after, $queriesAfter] = $count();

        $this->assertSame([$this->supervisor->id], $before);
        $this->assertSame($before, $after);
        $this->assertSame($queries, $queriesAfter);
    }

    public function test_nobody_without_the_decide_permission_is_emailed(): void
    {
        Mail::fake();
        Role::findByName(RoleName::Approver->value)->revokePermissionTo(PermissionName::RequestsDecide->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->overtime($this->employee);

        Mail::assertNothingSent();
    }

    public function test_an_approver_request_emails_every_active_hr_admin_only(): void
    {
        Mail::fake();
        $this->member(RoleName::HrAdmin, ['email' => 'hr1@example.test']);
        $this->member(RoleName::HrAdmin, ['email' => 'hr2@example.test']);
        $this->member(RoleName::HrAdmin, ['email' => 'hr-off@example.test'])->forceFill(['status' => UserStatus::Suspended])->saveQuietly();

        $this->overtime($this->supervisor);

        $this->assertSame(['hr1@example.test', 'hr2@example.test'], $this->recipients(RequestSubmittedMail::class));
    }

    public function test_an_hr_admin_request_emails_the_assigned_supervisor_not_other_hr_admins(): void
    {
        Mail::fake();
        $hrAdmin = $this->member(RoleName::HrAdmin, ['email' => 'hr1@example.test']);
        $this->member(RoleName::HrAdmin, ['email' => 'hr2@example.test']);
        $this->supervise($this->supervisor, $hrAdmin);

        $this->leave($hrAdmin);

        $this->assertSame(['siti@example.test'], $this->recipients(RequestSubmittedMail::class));
    }

    public function test_the_submitted_email_holds_type_dates_requester_and_link_but_no_reason_or_evidence(): void
    {
        Mail::fake();
        Storage::fake(Evidence::DISK);

        $request = $this->leave($this->employee, evidence: UploadedFile::fake()->createWithContent('medical-letter.pdf', "%PDF-1.4\n%%EOF\n"));

        $mail = Mail::sent(RequestSubmittedMail::class)->sole();
        $this->assertSame('en', $mail->locale);
        $html = $mail->render();

        foreach (['Budi Employee', 'Hello Siti Supervisor,', 'Leave', 'Annual leave', '7 Dec 2026 – 8 Dec 2026', '2 working days', 'Review request'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('href="https://hr.example.test/approvals/leave/'.$request->id.'"', $html);
        $this->assertStringNotContainsString(self::REASON, $html);
        $this->assertStringNotContainsString('medical-letter', $html);
        $this->assertStringNotContainsString('evidence', $html);
        $this->assertStringNotContainsString($request->evidence_path, $html);
        $this->assertSame('A request from Budi Employee is waiting for your decision', $mail->envelope()->subject);
    }

    public function test_emails_follow_the_recipients_language(): void
    {
        Mail::fake();
        $this->supervisor->forceFill(['locale' => 'id'])->saveQuietly();

        $request = $this->overtime($this->employee);

        $mail = Mail::sent(RequestSubmittedMail::class)->sole();
        $this->assertSame('id', $mail->locale);
        $html = $mail->render();

        foreach (['Sebuah pengajuan menunggu keputusan Anda', 'Halo Siti Supervisor,', 'Lembur', '7 Des 2026, 22:00 – 8 Des 2026, 01:00 WIB', '3 jam', 'Tinjau pengajuan', 'Hak cipta dilindungi.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('https://hr.example.test/approvals/overtime/'.$request->id, $html);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_subject_and_text_part_follow_the_recipients_language_on_a_real_transport(): void
    {
        config(['mail.default' => 'array']);
        $this->supervisor->forceFill(['locale' => 'id'])->saveQuietly();

        $this->overtime($this->employee, '2026-12-09 17:00', '2026-12-09 18:00');
        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Pengajuan dari Budi Employee menunggu keputusan Anda', $message->getSubject());
        $this->assertStringContainsString('Sebuah pengajuan menunggu keputusan Anda', $message->getTextBody());
        $this->assertStringContainsString('9 Des 2026, 17:00 – 18:00 WIB', $message->getTextBody());
        $this->assertStringNotContainsString(self::REASON, $message->getTextBody());
    }

    public function test_approval_emails_the_requester_the_outcome_without_the_note(): void
    {
        $request = $this->leave($this->employee);
        Mail::fake();
        $this->employee->forceFill(['locale' => 'id'])->saveQuietly();

        app(Decisions::class)->approve($this->supervisor, 'leave', $request->id, self::NOTE);

        Mail::assertNotSent(RequestSubmittedMail::class);
        $mail = Mail::sent(RequestDecidedMail::class)->sole();
        $this->assertTrue($mail->hasTo('budi@example.test'));
        $this->assertSame('id', $mail->locale);
        $html = $mail->render();

        foreach (['Pengajuan Anda disetujui', 'Halo Budi Employee,', 'Cuti tahunan', '7 Des 2026 – 8 Des 2026', 'Disetujui', 'Lihat pengajuan'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('href="https://hr.example.test/requests/leave/'.$request->id.'"', $html);
        $this->assertStringNotContainsString(self::NOTE, $html);
        $this->assertStringNotContainsString(self::REASON, $html);
        $this->assertStringNotContainsString('Siti', $html);
        $this->assertSame('Pengajuan Anda disetujui', $mail->withLocale('id', fn () => $mail->envelope()->subject));
    }

    public function test_rejection_emails_the_requester_without_the_rejection_reason(): void
    {
        $request = $this->overtime($this->employee, '2026-11-05 17:00', '2026-11-05 19:00');
        Mail::fake();

        app(Decisions::class)->reject($this->supervisor, 'overtime', $request->id, self::REJECTION);

        $mail = Mail::sent(RequestDecidedMail::class)->sole();
        $this->assertTrue($mail->hasTo('budi@example.test'));
        $html = $mail->render();

        foreach (['Your request was rejected', 'Overtime', '5 Nov 2026, 17:00 – 19:00 WIB', '2 hours', 'Rejected'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('https://hr.example.test/requests/overtime/'.$request->id, $html);
        $this->assertStringNotContainsString(self::REJECTION, $html);
        $this->assertStringNotContainsString(self::REASON, $html);
        $this->assertSame('Your request was rejected', $mail->envelope()->subject);
    }

    public function test_no_email_for_a_cancellation_a_refused_submission_or_a_refused_decision(): void
    {
        $request = $this->leave($this->employee);
        $other = $this->member(RoleName::Approver);
        Mail::fake();

        app(LeaveRequests::class)->cancel($this->employee, $request->id);

        try {
            $this->leave($this->employee, '2026-11-02', '2026-11-02');
            $this->fail('A date in the past must be refused.');
        } catch (ValidationException) {
        }

        $pending = $this->leave($this->employee, '2026-12-14', '2026-12-14');
        Mail::fake();

        try {
            app(Decisions::class)->approve($other, 'leave', $pending->id);
            $this->fail('An unrelated Approver must be refused.');
        } catch (AuthorizationException) {
        }

        app(Decisions::class)->approve($this->supervisor, 'leave', $pending->id);

        try {
            app(Decisions::class)->reject($this->supervisor, 'leave', $pending->id, self::REJECTION);
            $this->fail('A decided request must not be decided again.');
        } catch (ValidationException) {
        }

        // Only the one real decision sent an email.
        $this->assertSame(['budi@example.test'], $this->recipients(RequestDecidedMail::class));
        Mail::assertNotSent(RequestSubmittedMail::class);
    }

    public function test_a_requester_who_is_no_longer_active_gets_no_decision_email(): void
    {
        $request = $this->leave($this->employee);
        Mail::fake();
        $this->employee->forceFill(['status' => UserStatus::Suspended])->saveQuietly();

        app(Decisions::class)->reject($this->supervisor, 'leave', $request->id, self::REJECTION);

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        Mail::assertNothingSent();
        $this->assertCount(0, $this->failureAudits());
    }

    public function test_emails_are_sent_after_the_transaction_committed(): void
    {
        config(['mail.default' => 'array']);
        $levels = [];
        Event::listen(MessageSending::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });
        // RefreshDatabase wraps each test in a transaction, so "committed" is this level, not zero.
        $base = DB::transactionLevel();

        $request = $this->leave($this->employee);
        app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);

        $this->assertSame([$base, $base], $levels);
        $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_failed_transport_keeps_the_request_audits_and_logs_only_class_names(): void
    {
        $second = $this->member(RoleName::Approver, ['email' => 'second@example.test']);
        $this->supervise($second, $this->employee);
        $this->breakTransport();
        Log::spy();

        $request = $this->leave($this->employee);

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(2, app(RequestNotifications::class)->failures($request));
        $audits = $this->failureAudits();
        $this->assertCount(2, $audits);
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $second->id], $audits->pluck('metadata.recipient_id')->all());
        $this->assertSame(['submitted'], $audits->pluck('metadata.notification')->unique()->values()->all());
        $this->assertSame($this->employee->id, $audits[0]->actor_id);
        $this->assertSame([LeaveRequest::class, $request->id], [$audits[0]->subject_type, $audits[0]->subject_id]);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);

            return $message === 'Mail delivery failed.'
                && $context['mailable'] === RequestSubmittedMail::class
                && str_contains($context['exception'], 'Exception')
                && ! str_contains($flat, '@example.test')
                && ! str_contains($flat, 'Budi');
        })->twice();
    }

    public function test_a_failed_transport_keeps_the_decision_and_audits_it(): void
    {
        $request = $this->leave($this->employee);
        $this->breakTransport();

        $decided = app(Decisions::class)->approve($this->supervisor, 'leave', $request->id);

        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(2, DB::table('leave_periods')->where('user_id', $this->employee->id)->value('used'));
        $this->assertSame(1, app(RequestNotifications::class)->failures($decided));
        $audit = $this->failureAudits()->sole();
        $this->assertSame(['notification' => 'decided', 'recipient_id' => $this->employee->id], $audit->metadata);
        $this->assertSame($this->supervisor->id, $audit->actor_id);
    }

    public function test_any_other_error_while_notifying_is_contained_and_audited(): void
    {
        Mail::fake();
        Log::spy();
        $this->mock(Deciders::class, function ($mock) {
            $mock->shouldReceive('of')->andThrow(new RuntimeException('secret@example.test leaked in a message'));
        });

        $request = $this->overtime($this->employee);

        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(1, app(RequestNotifications::class)->failures($request));
        $this->assertSame(['notification' => 'submitted', 'recipient_id' => null], $this->failureAudits()->sole()->metadata);
        Log::shouldHaveReceived('error')->with('Request notification failed.', ['notification' => 'submitted', 'exception' => RuntimeException::class])->once();
        Mail::assertNothingSent();
    }

    public function test_the_pages_warn_when_an_email_failed_and_stay_quiet_otherwise(): void
    {
        config(['mail.default' => 'array']);

        Livewire::actingAs($this->employee)->test(RequestIndex::class)
            ->call('openLeave')
            ->set('starts_on', '2026-12-07')
            ->set('ends_on', '2026-12-07')
            ->set('reason', self::REASON)
            ->call('submitLeave')
            ->assertHasNoErrors()
            ->assertDispatched('toast', tone: 'success')
            ->assertNotDispatched('toast', tone: 'warning');

        $this->breakTransport();

        Livewire::actingAs($this->employee)->test(RequestIndex::class)
            ->call('openOvertime')
            ->set('start_date', '2026-12-08')
            ->set('end_date', '2026-12-08')
            ->set('start_hour', '17:00')
            ->set('end_hour', '19:00')
            ->set('overtime_reason', self::REASON)
            ->call('submitOvertime')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Overtime request submitted for 2 hours.', tone: 'success')
            ->assertDispatched('toast', message: 'The request was submitted, but an email to a decider could not be sent. Tell them yourself if it is urgent.', tone: 'warning');

        $overtime = OvertimeRequest::query()->sole();
        $this->assertSame(RequestStatus::Pending, $overtime->status);

        Livewire::actingAs($this->supervisor)->test(ApprovalShow::class, ['type' => 'overtime', 'id' => (string) $overtime->id])
            ->call('openApprove')
            ->call('approve')
            ->assertDispatched('toast', message: 'The request was approved.', tone: 'success')
            ->assertDispatched('toast', message: 'The decision was saved, but the email to the requester could not be sent.', tone: 'warning');

        $this->assertSame(RequestStatus::Approved, $overtime->fresh()->status);

        config(['mail.default' => 'array']);
        $leave = LeaveRequest::query()->sole();

        Livewire::actingAs($this->supervisor)->test(ApprovalShow::class, ['type' => 'leave', 'id' => (string) $leave->id])
            ->call('openReject')
            ->set('reason', self::REJECTION)
            ->call('reject')
            ->assertDispatched('toast', message: 'The request was rejected.', tone: 'success')
            ->assertNotDispatched('toast', tone: 'warning');
    }

    public function test_a_name_cannot_inject_a_link_into_the_email(): void
    {
        Mail::fake();
        $this->employee->forceFill(['name' => '[Click here](https://evil.test) <b>x</b>'])->saveQuietly();

        $this->leave($this->employee);

        $html = Mail::sent(RequestSubmittedMail::class)->sole()->render();
        $this->assertStringNotContainsString('href="https://evil.test"', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('[Click here](https://evil.test)', $html);
    }
}
