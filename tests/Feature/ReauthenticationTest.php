<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\PendingLogin;
use App\Support\Reauthentication;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class ReauthenticationTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->logIn($this->user);
    }

    /**
     * Post a form to the confirmation page.
     *
     * @param  array<string, string>  $input
     */
    private function confirm(array $input): TestResponse
    {
        return $this->from(route('security.confirm'))->post(route('security.confirm.store'), $input);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitiveRoutes(): array
    {
        return [
            'identity form' => ['get', 'profile.identity'],
            'identity update' => ['post', 'profile.identity.update'],
            'password form' => ['get', 'security.password'],
            'password update' => ['post', 'security.password.update'],
            'recovery form' => ['get', 'security.recovery-codes'],
            'recovery regenerate' => ['post', 'security.recovery-codes.store'],
            'authenticator form' => ['get', 'security.authenticator'],
            'authenticator confirm' => ['post', 'security.authenticator.store'],
        ];
    }

    #[DataProvider('sensitiveRoutes')]
    public function test_a_sensitive_route_refuses_a_session_without_a_proof(string $method, string $route): void
    {
        $this->{$method}(route($route))->assertRedirect(route('security.confirm'));
    }

    #[DataProvider('sensitiveRoutes')]
    public function test_a_sensitive_route_is_behind_the_proof_and_the_full_login_check(string $method, string $route): void
    {
        $middleware = Route::getRoutes()->getByName($route)->gatherMiddleware();

        $this->assertContains('fully.authenticated', $middleware);
        $this->assertContains('auth.session', $middleware);
        $this->assertNotEmpty(array_filter($middleware, fn (string $name) => str_starts_with($name, 'reauthenticated:')));
    }

    public function test_a_refused_post_changes_nothing(): void
    {
        $secret = $this->user->two_factor_secret;
        $codes = RecoveryCode::regenerateFor($this->user);

        $this->post(route('security.password.update'), ['password' => 'Brand-New-Horse-77', 'password_confirmation' => 'Brand-New-Horse-77']);
        $this->post(route('profile.identity.update'), ['username' => 'changed', 'email' => 'changed@example.com', 'email_confirmation' => 'changed@example.com']);
        $this->post(route('security.recovery-codes.store'));
        $this->post(route('security.authenticator.store'), ['code' => $this->totp($secret)]);

        $fresh = $this->user->fresh();

        $this->assertSame($this->user->password, $fresh->password);
        $this->assertSame($this->user->username, $fresh->username);
        $this->assertSame($this->user->email, $fresh->email);
        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertTrue(RecoveryCode::consume($this->user, $codes[0]));
    }

    public function test_a_password_and_code_grant_the_proof_and_return_to_the_intended_page(): void
    {
        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));

        $this->confirmIdentity($this->user)->assertRedirect(route('security.password'));

        $this->get(route('security.password'))->assertOk();
        $this->assertSame(1, AuditLog::where('event', AuditEvent::ReauthenticationSucceeded->value)->where('metadata->method', 'totp')->count());
    }

    public function test_the_confirmation_page_loads_with_the_app_layout(): void
    {
        $this->get(route('security.confirm'))->assertOk()->assertSee('Confirm your identity')->assertSee('Skip to content');
    }

    public function test_a_client_cannot_choose_where_the_confirmation_returns_to(): void
    {
        $this->travel(30)->seconds();

        $this->post(route('security.confirm.store'), [
            'password' => 'password',
            'code' => $this->totp($this->user->two_factor_secret),
            'redirect' => 'https://evil.example',
            'intended' => 'https://evil.example',
        ])->assertRedirect(route('security.index'));
    }

    public function test_a_wrong_password_spends_neither_the_code_nor_a_recovery_code(): void
    {
        $this->travel(30)->seconds();
        $before = $this->user->fresh()->two_factor_last_timestep;
        $code = $this->totp($this->user->two_factor_secret);
        $recovery = RecoveryCode::regenerateFor($this->user);

        $this->confirm(['password' => 'wrong', 'code' => $code])
            ->assertRedirect(route('security.confirm'))
            ->assertSessionHasErrors(['password' => 'The password or code is invalid.']);

        $this->confirm(['password' => 'wrong', 'recovery_code' => $recovery[0]])
            ->assertSessionHasErrors(['password' => 'The password or code is invalid.']);

        $this->assertSame($before, $this->user->fresh()->two_factor_last_timestep);
        $this->assertSame(10, $this->user->recoveryCodes()->whereNull('used_at')->count());
        $this->assertSame(2, AuditLog::where('event', AuditEvent::ReauthenticationFailed->value)->count());
        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_a_wrong_code_gives_the_same_message_and_no_proof(): void
    {
        $this->travel(30)->seconds();

        $this->confirm(['password' => 'password', 'code' => '000000'])
            ->assertSessionHasErrors(['password' => 'The password or code is invalid.']);

        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_a_failed_confirmation_is_no_login_failure(): void
    {
        $before = LoginHistory::count();

        $this->confirm(['password' => 'wrong', 'code' => '000000']);

        $this->assertSame($before, LoginHistory::count());
    }

    public function test_the_code_that_finished_the_login_is_refused_as_a_replay(): void
    {
        $this->confirm(['password' => 'password', 'code' => $this->totp($this->user->two_factor_secret)])
            ->assertSessionHasErrors('password');

        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_a_code_used_to_confirm_cannot_confirm_again(): void
    {
        $this->travel(30)->seconds();
        $code = $this->totp($this->user->two_factor_secret);

        $this->confirm(['password' => 'password', 'code' => $code])->assertRedirect(route('security.index'));

        session()->forget('reauthentication');

        $this->confirm(['password' => 'password', 'code' => $code])->assertSessionHasErrors('password');
    }

    public function test_a_code_used_to_confirm_cannot_finish_a_login(): void
    {
        $this->travel(30)->seconds();
        $code = $this->totp($this->user->two_factor_secret);

        $this->confirm(['password' => 'password', 'code' => $code])->assertRedirect();

        $this->post(route('logout'));
        $this->startNextRequest();
        $this->submitPassword($this->user);

        $this->submitCode($code)->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_can_stand_in_for_the_authenticator_once(): void
    {
        $codes = RecoveryCode::regenerateFor($this->user);

        $this->confirm(['password' => 'password', 'recovery_code' => strtolower($codes[0])])->assertRedirect(route('security.index'));

        $this->get(route('security.password'))->assertOk();
        $this->assertSame(1, $this->user->recoveryCodes()->whereNotNull('used_at')->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::RecoveryCodeUsed->value)->where('subject_id', $this->user->id)->count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::ReauthenticationSucceeded->value)->where('metadata->method', 'recovery_code')->count());

        session()->forget('reauthentication');

        $this->confirm(['password' => 'password', 'recovery_code' => $codes[0]])->assertSessionHasErrors('password');
    }

    public function test_a_wrong_recovery_code_grants_nothing(): void
    {
        RecoveryCode::regenerateFor($this->user);

        $this->confirm(['password' => 'password', 'recovery_code' => 'WRONG-WRONG-WRONG'])
            ->assertSessionHasErrors(['password' => 'The password or code is invalid.']);

        $this->assertSame(0, AuditLog::where('event', AuditEvent::RecoveryCodeUsed->value)->count());
        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_exactly_one_second_factor_is_required(): void
    {
        $this->confirm(['password' => 'password'])->assertSessionHasErrors('code');
        $this->confirm(['password' => 'password', 'code' => '123456', 'recovery_code' => 'ABCD'])->assertSessionHasErrors('code');
        $this->confirm(['password' => 'password', 'code' => '12345'])->assertSessionHasErrors('code');
        $this->confirm(['code' => '123456'])->assertSessionHasErrors('password');
        $this->confirm(['password' => 'password', 'recovery_code' => str_repeat('A', 65)])->assertSessionHasErrors('recovery_code');
    }

    public function test_confirmation_is_throttled_even_with_the_right_answer(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->confirm(['password' => 'wrong', 'code' => '000000']);
        }

        $this->travel(30)->seconds();

        $this->confirm(['password' => 'password', 'code' => $this->totp($this->user->two_factor_secret)]);

        $this->assertThrottled();
        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_confirmation_shares_its_bucket_with_the_login_stage(): void
    {
        $key = AuthThrottle::twoFactorKey(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']), $this->user);

        $this->confirm(['password' => 'wrong', 'code' => '000000']);

        $this->assertSame(1, RateLimiter::attempts($key));

        foreach (range(1, 4) as $attempt) {
            AuthThrottle::exceeded($key);
        }

        $this->confirm(['password' => 'password', 'code' => '000000']);

        $this->assertThrottled();
    }

    public function test_a_success_clears_the_bucket(): void
    {
        $key = AuthThrottle::twoFactorKey(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']), $this->user);

        $this->confirm(['password' => 'wrong', 'code' => '000000']);
        $this->confirmIdentity($this->user);

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_the_proof_lasts_ten_minutes_exactly(): void
    {
        $this->confirmIdentity($this->user);

        $this->travel(Reauthentication::TTL_SECONDS - 1)->seconds();
        $this->get(route('security.password'))->assertOk();

        $this->travel(1)->seconds();
        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));

        $this->assertSame(600, Reauthentication::TTL_SECONDS);
    }

    public function test_activity_does_not_extend_the_proof(): void
    {
        $this->confirmIdentity($this->user);

        foreach (range(1, 4) as $step) {
            $this->travel(120)->seconds();
            $this->get(route('security.password'))->assertOk();
        }

        $this->travel(121)->seconds();

        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_the_proof_dies_when_the_password_hash_changes(): void
    {
        $this->confirmIdentity($this->user);
        $this->get(route('security.password'))->assertOk();

        $this->user->forceFill(['password' => 'Changed-Elsewhere-55'])->save();

        // Keeps auth.session satisfied, so that only the proof itself can refuse this request.
        $this->session(['password_hash_web' => PendingLogin::passwordHash($this->user->fresh())]);
        $this->startNextRequest();

        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_proof_for_another_password_is_refused_directly(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(session()->driver());
        $other = $this->enrolledUser(['password' => 'A-Different-One-42']);

        Reauthentication::grant($request, $other);

        $this->assertTrue(Reauthentication::isValid($request, $other));
        $this->assertFalse(Reauthentication::isValid($request, $this->user));
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function completedActions(): array
    {
        return [
            'password' => ['security.password.update', ['password' => 'Brand-New-Horse-77', 'password_confirmation' => 'Brand-New-Horse-77']],
            'recovery codes' => ['security.recovery-codes.store', []],
            'identity' => ['profile.identity.update', ['username' => 'someone.new', 'email' => 'new@example.com', 'email_confirmation' => 'new@example.com']],
        ];
    }

    #[DataProvider('completedActions')]
    public function test_a_completed_action_spends_the_proof(string $route, array $input): void
    {
        $this->confirmIdentity($this->user);
        $this->get(route('security.password'))->assertOk();

        $this->assertLessThan(400, $this->post(route($route), $input)->getStatusCode());
        $this->startNextRequest();

        $this->get(route('security.password'))->assertRedirect(route('security.confirm'));
    }

    public function test_a_refused_form_keeps_the_proof(): void
    {
        $this->confirmIdentity($this->user);

        $this->from(route('security.password'))->post(route('security.password.update'), ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->get(route('security.password'))->assertOk();
    }

    public function test_a_guest_reaches_nothing(): void
    {
        $this->post(route('logout'));
        $this->startNextRequest();

        foreach (['security.confirm', 'security.index', 'security.sessions', 'security.login-history', 'profile.show', 'security.password'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $this->post(route('security.confirm.store'), ['password' => 'password', 'code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_a_user_who_only_passed_the_password_stage_reaches_nothing(): void
    {
        $this->post(route('logout'));
        $this->startNextRequest();
        $this->travel(30)->seconds();
        $this->submitPassword($this->user);

        $this->get(route('security.confirm'))->assertRedirect(route('login'));
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }
}
