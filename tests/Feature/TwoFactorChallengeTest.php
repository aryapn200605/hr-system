<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\TwoFactor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
    }

    public function test_the_challenge_needs_a_pending_login(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('two-factor.recovery.store'), ['recovery_code' => 'ABCD'])->assertRedirect(route('login'));
    }

    public function test_the_challenge_page_is_not_cached(): void
    {
        $this->submitPassword($this->user);

        $response = $this->get(route('two-factor.challenge'))->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_valid_code_logs_the_user_in_and_goes_to_the_dashboard(): void
    {
        $this->submitPassword($this->user);
        $this->assertGuest();

        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertFalse(session()->has('pending_login'));

        $history = LoginHistory::where('result', LoginResult::Success)->sole();
        $this->assertSame($this->user->id, $history->user_id);

        $audit = AuditLog::where('event', AuditEvent::LoginSucceeded)->sole();
        $this->assertSame($this->user->id, $audit->actor_id);
        $this->assertSame(['method' => 'totp'], $audit->metadata);
    }

    public function test_a_full_login_regenerates_the_session(): void
    {
        $this->submitPassword($this->user);
        $sessionId = str_repeat('b', 40);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->submitCode($this->totp($this->user->two_factor_secret));

        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_the_code_is_validated(): void
    {
        $this->submitPassword($this->user);

        foreach (['', '12345', '1234567', 'abcdef'] as $code) {
            $this->submitCode($code)->assertSessionHasErrors('code');
        }

        $this->assertGuest();
        $this->assertNull($this->user->fresh()->two_factor_last_timestep);
    }

    public function test_a_wrong_code_is_refused_and_recorded(): void
    {
        $wrong = $this->totp($this->user->two_factor_secret) === '000000' ? '000001' : '000000';
        $this->submitPassword($this->user);

        $this->submitCode($wrong)
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHasErrors(['code' => 'The code is invalid.']);

        $this->assertGuest();
        $this->assertTrue(session()->has('pending_login'));
        $this->assertNull($this->user->fresh()->two_factor_last_timestep);

        $history = LoginHistory::sole();
        $this->assertSame(LoginResult::FailedTotp->value, $history->result);
        $this->assertSame($this->user->id, $history->user_id);
        $this->assertSame(1, AuditLog::where('event', AuditEvent::LoginFailed)->count());
    }

    public function test_replaying_a_code_is_refused(): void
    {
        $code = $this->totp($this->user->two_factor_secret);

        $this->submitPassword($this->user);
        $this->submitCode($code)->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->startNextRequest();

        // Time must move on: MySQL reports no change for an update to the same second.
        $this->travel(1)->seconds();

        $this->submitPassword($this->user);
        $this->submitCode($code)->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_request_holding_a_stale_user_cannot_claim_a_timestep_again(): void
    {
        // Two requests that loaded the user before either verified, as in a race.
        $first = $this->user->fresh();
        $second = $this->user->fresh();
        $code = $this->totp($this->user->two_factor_secret);

        $this->assertTrue(TwoFactor::verify($first, $code));

        $this->travel(1)->seconds();

        $this->assertFalse(TwoFactor::verify($second, $code));
    }

    public function test_a_code_older_than_the_last_accepted_timestep_is_refused(): void
    {
        $secret = $this->user->two_factor_secret;
        $previous = $this->totp($secret, -1);

        $this->logIn($this->user);
        $this->post(route('logout'));
        $this->startNextRequest();

        $this->submitPassword($this->user);
        $this->submitCode($previous)->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_accepted_timestep_is_stored(): void
    {
        $this->submitPassword($this->user);
        $this->submitCode($this->totp($this->user->two_factor_secret, 1))->assertRedirect(route('dashboard'));

        $this->assertSame(
            (int) floor(now()->getTimestamp() / 30) + 1,
            $this->user->fresh()->two_factor_last_timestep,
        );
    }

    public function test_one_step_of_clock_drift_is_tolerated(): void
    {
        $this->submitPassword($this->user);
        $this->submitCode($this->totp($this->user->two_factor_secret, -1))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_two_steps_of_clock_drift_are_refused(): void
    {
        $this->submitPassword($this->user);

        $this->submitCode($this->totp($this->user->two_factor_secret, -2))->assertSessionHasErrors('code');
        $this->submitCode($this->totp($this->user->two_factor_secret, 2))->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_pending_login_expires(): void
    {
        $this->submitPassword($this->user);

        $this->travel(301)->seconds();

        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse(session()->has('pending_login'));
    }

    public function test_an_account_suspended_during_the_pending_login_is_stopped(): void
    {
        $this->submitPassword($this->user);
        $this->user->suspend();

        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse(session()->has('pending_login'));
    }

    public function test_the_enrollment_page_is_closed_to_an_enrolled_user(): void
    {
        $this->submitPassword($this->user);

        $this->get(route('two-factor.enrollment'))->assertRedirect(route('two-factor.challenge'));
        $this->post(route('two-factor.enrollment.store'), ['code' => $this->totp($this->user->two_factor_secret)])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_a_pending_login_can_be_cancelled(): void
    {
        $this->submitPassword($this->user);

        $this->post(route('two-factor.cancel'))->assertRedirect(route('login'));

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_codes_are_throttled_even_when_correct(): void
    {
        $this->submitPassword($this->user);

        foreach (range(1, 5) as $attempt) {
            $this->submitCode('000000');
        }

        $this->submitCode($this->totp($this->user->two_factor_secret));

        $this->assertThrottled();
        $this->assertGuest();
        $this->assertSame(5, LoginHistory::count());
    }

    public function test_recovery_codes_share_the_totp_limit(): void
    {
        $this->submitPassword($this->user);

        foreach (range(1, 3) as $attempt) {
            $this->submitCode('000000');
        }

        foreach (range(1, 2) as $attempt) {
            $this->submitRecoveryCode('WRONG-CODE');
        }

        $this->submitRecoveryCode('WRONG-CODE');

        $this->assertThrottled();
    }

    public function test_logging_in_again_does_not_reset_the_code_limit(): void
    {
        $this->submitPassword($this->user);

        foreach (range(1, 5) as $attempt) {
            $this->submitCode('000000');
        }

        $this->post(route('two-factor.cancel'));
        $this->submitPassword($this->user);
        $this->submitCode($this->totp($this->user->two_factor_secret));

        $this->assertThrottled();
        $this->assertGuest();
    }

    public function test_the_code_limit_is_per_ip_and_expires(): void
    {
        $this->submitPassword($this->user);

        foreach (range(1, 6) as $attempt) {
            $this->submitCode('000000');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('dashboard'));

        $this->post(route('logout'));
        $this->startNextRequest();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(61)->seconds();

        $this->submitPassword($this->user);
        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('dashboard'));
    }

    public function test_a_successful_login_clears_the_code_limit(): void
    {
        $this->submitPassword($this->user);

        foreach (range(1, 4) as $attempt) {
            $this->submitCode('000000');
        }

        $this->submitCode($this->totp($this->user->two_factor_secret))->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->startNextRequest();
        $this->travel(30)->seconds();

        $this->submitPassword($this->user);

        foreach (range(1, 5) as $attempt) {
            $this->submitCode('000000')->assertSessionHasErrors(['code' => 'The code is invalid.']);
        }
    }
}
