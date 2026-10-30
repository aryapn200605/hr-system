<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private const NEW_PASSWORD = 'Brand-New-Horse-77';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutDefer();
        Mail::fake();
    }

    private function requestLink(string $email): TestResponse
    {
        return $this->from(route('password.request'))->post(route('password.email'), ['email' => $email]);
    }

    private function lastToken(): string
    {
        return Str::afterLast(Mail::sent(PasswordResetMail::class)->last()->url, '/');
    }

    private function submitReset(string $email, string $password = self::NEW_PASSWORD, ?string $confirmation = null): TestResponse
    {
        return $this->from(route('password.reset'))->post(route('password.update'), [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    /**
     * What a client can observe from a forgot password request.
     *
     * @return array<string, mixed>
     */
    private function observe(TestResponse $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'message' => session('status'),
            'errors' => json_encode(session('errors')),
            'length' => $response->headers->get('Content-Length'),
        ];
    }

    public function test_an_active_user_gets_a_link_and_changes_the_password(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('Rina@Example.com')->assertRedirect(route('password.request'));

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('rina@example.com'));
        Mail::assertNothingQueued();

        $this->get(route('password.reset.start', $this->lastToken()))->assertRedirect(route('password.reset'));
        $this->get(route('password.reset'))->assertOk();

        $this->submitReset('rina@example.com')->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(['source' => 'reset'], AuditLog::where('event', AuditEvent::PasswordChanged->value)->sole()->metadata);
    }

    public function test_the_login_page_links_to_the_form_and_shows_the_outcome(): void
    {
        $this->get(route('login'))->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot your password?');
    }

    public function test_the_link_holds_no_email_address_and_the_token_leaves_the_url(): void
    {
        $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('rina@example.com');
        $token = $this->lastToken();

        $this->assertStringNotContainsString('rina', Mail::sent(PasswordResetMail::class)->last()->url);

        $response = $this->get(route('password.reset.start', $token));

        $this->assertStringNotContainsString($token, $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, $this->get(route('password.reset'))->getContent());
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
    }

    public function test_the_stored_token_is_hashed(): void
    {
        $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('rina@example.com');

        $stored = DB::table('password_reset_tokens')->sole()->token;

        $this->assertNotSame($this->lastToken(), $stored);
        $this->assertTrue(Hash::check($this->lastToken(), $stored));
    }

    public function test_the_email_is_responsive_html(): void
    {
        $mail = new PasswordResetMail('Rina Wijaya', 'token-value');
        $html = $mail->render();

        $this->assertStringContainsString('<!DOCTYPE html', $html);
        $this->assertStringContainsString('@media', $html);
        $this->assertStringContainsString($mail->url, $html);
    }

    public function test_the_forgot_response_is_identical_for_every_kind_of_address(): void
    {
        $this->enrolledUser(['email' => 'active@example.com']);
        User::factory()->pending()->create(['email' => 'pending@example.com']);
        User::factory()->suspended()->create(['email' => 'suspended@example.com']);

        $baseline = $this->observe($this->requestLink('active@example.com'));

        foreach ([
            'nobody@example.com',
            'pending@example.com',
            'suspended@example.com',
            // A second request inside the broker's 60 seconds is refused, which must not show.
            'active@example.com',
        ] as $email) {
            $this->assertSame($baseline, $this->observe($this->requestLink($email)), $email);
        }

        $this->assertNotNull($baseline['message']);
        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_a_failed_transport_gives_the_same_response_and_is_recorded(): void
    {
        $user = $this->enrolledUser(['email' => 'active@example.com']);
        $baseline = $this->observe($this->requestLink('nobody@example.com'));

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
        Mail::swap(new MailManager($this->app));

        $this->assertSame($baseline, $this->observe($this->requestLink('active@example.com')));

        $audit = AuditLog::where('event', AuditEvent::PasswordResetDeliveryFailed->value)->sole();

        $this->assertSame($user->id, $audit->subject_id);

        // No token is left behind, so the broker's throttle does not block a retry.
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_the_response_does_not_claim_the_email_was_sent(): void
    {
        $this->requestLink('nobody@example.com');

        $this->assertStringStartsWith('If this address', session('status'));
    }

    public function test_the_forgot_request_touches_no_account_before_the_response(): void
    {
        $this->enrolledUser(['email' => 'active@example.com']);

        // The test client terminates the kernel by itself, so this holds the work until the test lets go.
        $deferred = new class extends DeferredCallbackCollection
        {
            public bool $held = true;

            public function invokeWhen(?Closure $when = null): void
            {
                if (! $this->held) {
                    parent::invokeWhen($when);
                }
            }
        };

        $this->app->instance(DeferredCallbackCollection::class, $deferred);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->requestLink('active@example.com');

        $this->assertSame([], array_filter($queries, fn (string $sql) => str_contains($sql, 'users') || str_contains($sql, 'password_reset_tokens')));
        $this->assertCount(1, $deferred);
        Mail::assertNothingSent();

        $deferred->held = false;
        $deferred->invoke();

        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_the_forgot_response_ends_with_its_content_length(): void
    {
        $response = $this->requestLink('nobody@example.com');

        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
    }

    public function test_pending_and_suspended_accounts_get_no_link(): void
    {
        User::factory()->pending()->create(['email' => 'pending@example.com']);
        User::factory()->suspended()->create(['email' => 'suspended@example.com']);

        $this->requestLink('pending@example.com');
        $this->requestLink('suspended@example.com');

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_a_valid_token_cannot_reset_an_account_suspended_since(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);
        $hash = $user->password;

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));

        $user->suspend();

        $this->submitReset('rina@example.com')->assertSessionHasErrors('email');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_a_valid_token_for_a_pending_account_is_refused(): void
    {
        $user = User::factory()->pending()->create(['email' => 'pending@example.com']);
        $token = Password::createToken($user);

        $this->get(route('password.reset.start', $token));

        $this->submitReset('pending@example.com')->assertSessionHasErrors('email');

        $this->assertNull($user->fresh()->password);
    }

    public function test_every_reset_failure_gives_the_same_error(): void
    {
        $this->enrolledUser(['email' => 'active@example.com']);
        User::factory()->suspended()->create(['email' => 'suspended@example.com']);

        $this->requestLink('active@example.com');
        $this->get(route('password.reset.start', 'wrong-token'));

        $errors = [];

        foreach (['active@example.com', 'nobody@example.com', 'suspended@example.com'] as $email) {
            $this->submitReset($email)->assertRedirect(route('password.reset'))->assertSessionHasErrors('email');
            $errors[] = json_encode(session('errors'));
        }

        $this->assertCount(1, array_unique($errors));
    }

    public function test_a_used_token_is_refused(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('rina@example.com');
        $token = $this->lastToken();

        $this->get(route('password.reset.start', $token));
        $this->submitReset('rina@example.com')->assertRedirect(route('login'));
        $hash = $user->fresh()->password;

        $this->get(route('password.reset.start', $token));
        $this->submitReset('rina@example.com', 'Another-Horse-55')->assertSessionHasErrors('email');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->submitReset('rina@example.com')->assertSessionHasErrors('email');
    }

    public function test_a_weak_password_is_refused(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);
        $hash = $user->password;

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));

        $this->submitReset('rina@example.com', 'short1A')->assertSessionHasErrors('password');
        $this->submitReset('rina@example.com', self::NEW_PASSWORD, 'Mismatch-Horse-77')->assertSessionHasErrors('password');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_the_reset_form_needs_a_link(): void
    {
        $this->get(route('password.reset'))->assertRedirect(route('password.request'));
        $this->post(route('password.update'), [
            'email' => 'rina@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('password.request'));
    }

    public function test_a_reset_does_not_log_in_or_touch_two_factor(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);
        $secret = $user->two_factor_secret;
        $enrolledAt = $user->two_factor_enrolled_at;
        $user->forceFill(['two_factor_last_timestep' => 123])->save();
        $user->recoveryCodes()->create(['code_hash' => str_repeat('a', 64)]);

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));
        $this->submitReset('rina@example.com');

        $this->assertGuest();
        $this->assertFalse(session()->has('pending_login'));

        $fresh = $user->fresh();
        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertTrue($enrolledAt->equalTo($fresh->two_factor_enrolled_at));
        $this->assertSame(123, $fresh->two_factor_last_timestep);
        $this->assertSame(1, $fresh->recoveryCodes()->count());

        // The new password only reaches the second factor.
        $this->submitPassword($fresh, self::NEW_PASSWORD)->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_old_password_stops_working(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));
        $this->submitReset('rina@example.com');

        $this->submitPassword($user, 'password')->assertSessionHasErrors('identifier');
    }

    public function test_a_reset_ends_the_other_sessions_of_that_user_only(): void
    {
        $user = $this->enrolledUser(['email' => 'rina@example.com']);
        $other = $this->enrolledUser();
        $rememberToken = $user->remember_token;

        foreach (['mine-1' => $user->id, 'mine-2' => $user->id, 'theirs' => $other->id, 'guest' => null] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }

        $this->requestLink('rina@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));
        $this->submitReset('rina@example.com');

        $this->assertSame(['guest', 'theirs'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
    }

    public function test_forgot_requests_are_throttled_per_email_and_ip(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->requestLink('nobody@example.com')->assertSessionHasNoErrors();
        }

        $this->requestLink('nobody@example.com');
        $this->assertThrottled();

        $this->requestLink('someone-else@example.com')->assertSessionHasNoErrors();
    }

    public function test_reset_attempts_are_throttled_per_ip(): void
    {
        $this->get(route('password.reset.start', 'wrong-token'));

        foreach (range(1, 5) as $attempt) {
            $this->submitReset('rina@example.com');
        }

        $this->submitReset('rina@example.com');

        $this->assertThrottled();
    }

    public function test_an_invalid_email_is_refused_without_a_lookup(): void
    {
        $this->requestLink('not-an-email')->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_a_bootstrapped_admin_who_never_enrolled_is_still_forced_into_enrollment(): void
    {
        $user = User::factory()->withRole(RoleName::HrAdmin)->create(['email' => 'admin@example.com']);

        $this->requestLink('admin@example.com');
        $this->get(route('password.reset.start', $this->lastToken()));
        $this->submitReset('admin@example.com');

        $this->submitPassword($user->fresh(), self::NEW_PASSWORD)->assertRedirect(route('two-factor.enrollment'));
        $this->assertGuest();
    }
}
