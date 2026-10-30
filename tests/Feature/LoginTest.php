<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuthThrottle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failureCases(): array
    {
        return [
            'unknown account' => ['unknown'],
            'wrong password' => ['wrong-password'],
            'pending account' => ['pending'],
            'pending account with a password' => ['pending-with-password'],
            'suspended account' => ['suspended'],
        ];
    }

    /**
     * Attempt the login for a failure case.
     */
    private function attempt(string $case): TestResponse
    {
        $identifier = match ($case) {
            'unknown' => 'nobody',
            'wrong-password' => User::factory()->create()->username,
            'pending' => User::factory()->pending()->create()->username,
            'pending-with-password' => User::factory()->pending()->create(['password' => 'password'])->username,
            'suspended' => User::factory()->suspended()->create()->username,
        };

        return $this->from(route('login'))->post(route('login.store'), [
            'identifier' => $identifier,
            'password' => $case === 'wrong-password' ? 'not-the-password' : 'password',
        ]);
    }

    /**
     * Replace the hasher with one that records every check and still does the real work.
     */
    private function recordHashChecks(): object
    {
        $recorder = new class(app('hash')) implements Hasher
        {
            /** @var list<string|null> */
            public array $hashes = [];

            public function __construct(private Hasher $hasher) {}

            public function info($hashedValue)
            {
                return $this->hasher->info($hashedValue);
            }

            public function make($value, array $options = [])
            {
                return $this->hasher->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                $this->hashes[] = $hashedValue;

                return $this->hasher->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->hasher->needsRehash($hashedValue, $options);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->hasher->$method(...$arguments);
            }
        };

        $this->app->instance('hash', $recorder);
        Hash::clearResolvedInstance('hash');

        return $recorder;
    }

    public function test_the_login_form_is_shown_to_guests(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Username or email');
    }

    public function test_a_valid_password_starts_a_pending_login_without_logging_in(): void
    {
        $user = $this->enrolledUser();

        $this->submitPassword($user)->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame(['user_id', 'expires_at'], array_keys(session('pending_login')));
        $this->assertSame($user->id, session('pending_login.user_id'));
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertSame(0, LoginHistory::where('result', LoginResult::Success)->count());
    }

    public function test_an_email_in_any_case_identifies_the_account(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);

        $this->post(route('login.store'), ['identifier' => 'RINA@example.com', 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertSame($user->id, session('pending_login.user_id'));
    }

    public function test_a_valid_password_regenerates_the_session(): void
    {
        $user = $this->enrolledUser();
        $sessionId = str_repeat('a', 40);

        $this->withCookie(config('session.cookie'), $sessionId)->submitPassword($user);

        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_input_is_validated(): void
    {
        $this->from(route('login'))->post(route('login.store'), [])
            ->assertSessionHasErrors(['identifier', 'password']);

        $this->from(route('login'))->post(route('login.store'), ['identifier' => ['a'], 'password' => 'x'])
            ->assertSessionHasErrors('identifier');

        $this->assertSame(0, LoginHistory::count());
    }

    #[DataProvider('failureCases')]
    public function test_every_failure_gets_the_same_error_and_exactly_one_real_hash_check(string $case): void
    {
        $hasher = $this->recordHashChecks();

        $response = $this->attempt($case);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['identifier' => __('auth.failed')]);
        $this->assertGuest();
        $this->assertFalse(session()->has('pending_login'));

        $this->assertCount(1, $hasher->hashes);
        $this->assertSame(60, strlen((string) $hasher->hashes[0]));
        $this->assertStringStartsWith('$2y$', $hasher->hashes[0]);
    }

    #[DataProvider('failureCases')]
    public function test_every_failure_writes_one_history_row_and_one_audit_row(string $case): void
    {
        $this->attempt($case);

        $this->assertSame(1, LoginHistory::count());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::LoginFailed)->count());
    }

    public function test_failures_are_recorded_with_their_result_and_without_the_identifier(): void
    {
        $suspended = User::factory()->suspended()->create();

        $this->post(route('login.store'), ['identifier' => 'typed-by-mistake', 'password' => 'secret-guess'], ['User-Agent' => 'TestAgent/1.0']);
        $this->post(route('login.store'), ['identifier' => $suspended->username, 'password' => 'password']);

        $unknown = LoginHistory::where('result', LoginResult::FailedCredentials)->sole();
        $this->assertNull($unknown->user_id);
        $this->assertSame('TestAgent/1.0', $unknown->user_agent);
        $this->assertSame('127.0.0.1', $unknown->ip_address);
        $this->assertNotNull($unknown->occurred_at);

        $status = LoginHistory::where('result', LoginResult::FailedAccountStatus)->sole();
        $this->assertSame($suspended->id, $status->user_id);

        $stored = LoginHistory::all()->toJson().AuditLog::all()->toJson();
        $this->assertStringNotContainsString('typed-by-mistake', $stored);
        $this->assertStringNotContainsString('secret-guess', $stored);
    }

    public function test_the_login_form_does_not_keep_the_identifier(): void
    {
        $this->from(route('login'))->post(route('login.store'), ['identifier' => 'my-password-by-mistake', 'password' => '']);

        $this->assertNull(session()->getOldInput('identifier'));
    }

    public function test_attempts_are_throttled_per_identifier_and_ip_even_with_the_right_password(): void
    {
        $user = $this->enrolledUser();

        foreach (range(1, 5) as $attempt) {
            $this->submitPassword($user, 'wrong')->assertSessionHasErrors(['identifier' => __('auth.failed')]);
        }

        $hasher = $this->recordHashChecks();
        $response = $this->submitPassword($user);

        $this->assertThrottled();
        $response->assertRedirect(route('login'));
        $this->assertSame([], $hasher->hashes);
        $this->assertFalse(session()->has('pending_login'));
        $this->assertSame(5, LoginHistory::count());
    }

    public function test_unknown_accounts_are_throttled_the_same_way(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['identifier' => 'nobody', 'password' => 'guess']);
        }

        $this->post(route('login.store'), ['identifier' => 'nobody', 'password' => 'guess']);

        $this->assertThrottled();
    }

    public function test_the_login_limit_does_not_spread_to_other_identifiers_or_ips(): void
    {
        $user = $this->enrolledUser();
        $other = $this->enrolledUser();

        foreach (range(1, 6) as $attempt) {
            $this->submitPassword($user, 'wrong');
        }

        $this->submitPassword($other)->assertRedirect(route('two-factor.challenge'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->submitPassword($user)->assertRedirect(route('two-factor.challenge'));
    }

    public function test_the_login_limit_expires(): void
    {
        $user = $this->enrolledUser();

        foreach (range(1, 6) as $attempt) {
            $this->submitPassword($user, 'wrong');
        }

        $this->travel(61)->seconds();

        $this->submitPassword($user)->assertRedirect(route('two-factor.challenge'));
    }

    public function test_a_valid_password_clears_the_login_limit(): void
    {
        $user = $this->enrolledUser();

        foreach (range(1, 4) as $attempt) {
            $this->submitPassword($user, 'wrong');
        }

        $this->submitPassword($user)->assertRedirect(route('two-factor.challenge'));

        foreach (range(1, 5) as $attempt) {
            $this->submitPassword($user, 'wrong')->assertSessionHasErrors(['identifier' => __('auth.failed')]);
        }
    }

    public function test_throttle_keys_do_not_contain_the_identifier(): void
    {
        $key = AuthThrottle::loginKey(Request::create('/login'), 'rina@example.com');

        $this->assertStringNotContainsString('rina', $key);
    }

    public function test_a_logged_in_user_is_sent_away_from_the_login_page(): void
    {
        $this->logIn($this->enrolledUser());

        $this->get(route('login'))->assertRedirect('/dashboard');
    }

    public function test_the_last_login_time_changes_only_after_a_full_login(): void
    {
        $this->freezeTime();
        $user = User::factory()->twoFactorEnrolled()->withRole(RoleName::Approver)->create();

        $this->submitPassword($user);
        $this->assertNull($user->fresh()->last_login_at);

        $this->submitCode($this->totp($user->two_factor_secret));
        $this->assertSame(now()->getTimestamp(), $user->fresh()->last_login_at->getTimestamp());
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }
}
