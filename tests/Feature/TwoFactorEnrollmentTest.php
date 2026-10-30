<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\TwoFactor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class TwoFactorEnrollmentTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = User::factory()->withRole(RoleName::Employee)->create();
    }

    /**
     * The secret on the enrollment page, as a user would copy it.
     */
    private function openEnrollment(): string
    {
        $this->submitPassword($this->user)->assertRedirect(route('two-factor.enrollment'));

        $html = $this->get(route('two-factor.enrollment'))->assertOk()->getContent();

        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);

        return str_replace(' ', '', $matches[1]);
    }

    private function confirm(string $code): TestResponse
    {
        return $this->from(route('two-factor.enrollment'))->post(route('two-factor.enrollment.store'), ['code' => $code]);
    }

    /**
     * @return array<int, string>
     */
    private function codesIn(TestResponse $response): array
    {
        preg_match_all('/<li>([A-Z2-9]{4}(?:-[A-Z2-9]{4}){3})<\/li>/', $response->getContent(), $matches);

        return $matches[1];
    }

    public function test_an_incomplete_account_is_forced_into_enrollment_and_not_logged_in(): void
    {
        $this->submitPassword($this->user)->assertRedirect(route('two-factor.enrollment'));

        $this->assertGuest();
    }

    public function test_the_challenge_is_closed_to_an_incomplete_account(): void
    {
        $this->submitPassword($this->user);

        $this->get(route('two-factor.challenge'))->assertRedirect(route('two-factor.enrollment'));
        $this->post(route('two-factor.recovery.store'), ['recovery_code' => 'ABCD'])
            ->assertRedirect(route('two-factor.enrollment'));
    }

    public function test_the_page_shows_a_local_qr_code_and_keeps_the_secret_out_of_the_database(): void
    {
        $this->submitPassword($this->user);

        $response = $this->get(route('two-factor.enrollment'))->assertOk();

        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringNotContainsString('<?xml', $response->getContent());
        $this->assertStringNotContainsString('chart.googleapis.com', $response->getContent());
        $this->assertStringNotContainsString('otpauth://', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_the_qr_code_only_contains_local_vector_content(): void
    {
        $svg = TwoFactor::qrCodeSvg($this->user, TwoFactor::generateSecret());

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertDoesNotMatchRegularExpression('/(?:href|src)\s*=|<script/i', $svg);
    }

    public function test_the_secret_stays_the_same_on_refresh_and_is_encrypted_in_the_session(): void
    {
        $secret = $this->openEnrollment();

        $this->get(route('two-factor.enrollment'))->assertSee(implode(' ', str_split($secret, 4)));

        $stored = session('pending_login.secret');
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
    }

    public function test_a_valid_code_completes_enrollment_and_logs_in(): void
    {
        $secret = $this->openEnrollment();

        $response = $this->confirm($this->totp($secret))->assertOk()->assertViewIs('auth.recovery-codes');

        $user = $this->user->fresh();
        $this->assertTrue($user->hasCompletedTwoFactorEnrollment());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret'));
        $this->assertSame((int) floor(now()->getTimestamp() / 30), $user->two_factor_last_timestep);
        $this->assertNotNull($user->last_login_at);
        $this->assertAuthenticatedAs($this->user);
        $this->assertFalse(session()->has('pending_login'));

        $this->assertCount(10, $this->codesIn($response));
        $this->assertSame(10, $user->recoveryCodes()->count());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $audit = AuditLog::where('event', AuditEvent::TwoFactorEnrolled)->sole();
        $this->assertSame($this->user->id, $audit->actor_id);
        $this->assertNull($audit->metadata);
        $this->assertSame(['method' => 'enrollment'], AuditLog::where('event', AuditEvent::LoginSucceeded)->sole()->metadata);
        $this->assertSame(LoginResult::Success->value, LoginHistory::sole()->result);
    }

    public function test_recovery_codes_are_not_kept_in_the_session(): void
    {
        $secret = $this->openEnrollment();

        $codes = $this->codesIn($this->confirm($this->totp($secret)));

        $this->assertNotEmpty($codes);
        $this->assertStringNotContainsString($codes[0], json_encode(session()->all()));
    }

    public function test_recovery_codes_and_secret_cannot_be_shown_again(): void
    {
        $secret = $this->openEnrollment();
        $codes = $this->codesIn($this->confirm($this->totp($secret)));

        $this->startNextRequest();
        $this->get(route('two-factor.enrollment'))->assertRedirect('/dashboard');
        $this->post(route('two-factor.enrollment.store'), ['code' => $this->totp($secret)])->assertRedirect('/dashboard');

        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertStringNotContainsString($codes[0], $dashboard->getContent());
        $this->assertStringNotContainsString($secret, $dashboard->getContent());
    }

    public function test_the_code_is_validated(): void
    {
        $this->openEnrollment();

        $this->confirm('12')->assertSessionHasErrors('code');

        $this->assertFalse($this->user->fresh()->hasCompletedTwoFactorEnrollment());
        $this->assertGuest();
    }

    public function test_a_wrong_code_changes_nothing_and_is_recorded(): void
    {
        $secret = $this->openEnrollment();
        $wrong = $this->totp($secret) === '000000' ? '000001' : '000000';

        $this->confirm($wrong)->assertSessionHasErrors(['code' => 'The code is invalid.']);

        $user = $this->user->fresh();
        $this->assertFalse($user->hasCompletedTwoFactorEnrollment());
        $this->assertNull($user->two_factor_secret);
        $this->assertSame(0, $user->recoveryCodes()->count());
        $this->assertGuest();
        $this->assertSame(LoginResult::FailedTotp->value, LoginHistory::sole()->result);
    }

    public function test_the_enrollment_code_cannot_be_replayed_at_the_challenge(): void
    {
        $secret = $this->openEnrollment();
        $code = $this->totp($secret);

        $this->confirm($code);
        $this->post(route('logout'));
        $this->startNextRequest();

        $this->submitPassword($this->user)->assertRedirect(route('two-factor.challenge'));
        $this->submitCode($code)->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_confirmation_is_throttled_even_when_correct(): void
    {
        $secret = $this->openEnrollment();

        foreach (range(1, 5) as $attempt) {
            $this->confirm('000000');
        }

        $this->confirm($this->totp($secret));

        $this->assertThrottled();
        $this->assertFalse($this->user->fresh()->hasCompletedTwoFactorEnrollment());
    }

    public function test_enrollment_expires_after_ten_minutes(): void
    {
        $secret = $this->openEnrollment();

        $this->travel(301)->seconds();
        $this->get(route('two-factor.enrollment'))->assertOk();

        $this->travel(300)->seconds();
        $this->confirm($this->totp($secret))->assertRedirect(route('login'));

        $this->assertFalse($this->user->fresh()->hasCompletedTwoFactorEnrollment());
    }

    public function test_an_account_suspended_during_enrollment_is_stopped(): void
    {
        $secret = $this->openEnrollment();
        $this->user->suspend();

        $this->confirm($this->totp($secret))->assertRedirect(route('login'));

        $this->assertFalse($this->user->fresh()->hasCompletedTwoFactorEnrollment());
        $this->assertGuest();
    }

    public function test_a_second_enrollment_of_the_same_account_is_refused(): void
    {
        $first = $this->user->completeTwoFactorEnrollment(TwoFactor::generateSecret(), 1);
        $secret = $this->user->fresh()->two_factor_secret;

        $second = $this->user->completeTwoFactorEnrollment(TwoFactor::generateSecret(), 2);

        $this->assertCount(10, $first);
        $this->assertNull($second);
        $this->assertSame($secret, $this->user->fresh()->two_factor_secret);
        $this->assertSame(1, $this->user->fresh()->two_factor_last_timestep);
    }

    public function test_an_enrollment_finished_in_another_session_closes_this_one(): void
    {
        $secret = $this->openEnrollment();
        $otherSecret = TwoFactor::generateSecret();

        $this->user->completeTwoFactorEnrollment($otherSecret, 1);

        $this->confirm($this->totp($secret))->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame($otherSecret, $this->user->fresh()->two_factor_secret);
    }

    public function test_the_bootstrapped_admin_goes_through_enrollment_to_the_dashboard(): void
    {
        $admin = User::createFirstHrAdmin([
            'name' => 'Rina Admin',
            'username' => 'rina.admin',
            'email' => 'rina@example.com',
            'password' => 'Correct-Horse-42',
        ]);

        $this->post(route('login.store'), ['identifier' => 'rina.admin', 'password' => 'Correct-Horse-42'])
            ->assertRedirect(route('two-factor.enrollment'));

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->user = $admin;
        $html = $this->get(route('two-factor.enrollment'))->getContent();
        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);

        $this->confirm($this->totp(str_replace(' ', '', $matches[1])))->assertOk();

        $this->assertInstanceOf(RecoveryCode::class, $admin->recoveryCodes()->first());
        $this->get(route('dashboard'))->assertOk()->assertSee('Rina Admin');
    }
}
