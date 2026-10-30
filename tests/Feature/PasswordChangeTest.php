<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LogsIn;
use Tests\Concerns\SeedsSessions;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use LogsIn, RefreshDatabase, SeedsSessions;

    private const NEW_PASSWORD = 'Brand-New-Horse-77';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->logIn($this->user);
    }

    private function changePassword(string $password = self::NEW_PASSWORD, ?string $confirmation = null): TestResponse
    {
        return $this->from(route('security.password'))->post(route('security.password.update'), [
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    public function test_the_password_changes_and_the_current_session_stays_logged_in(): void
    {
        $this->confirmIdentity($this->user);

        $this->changePassword()->assertRedirect(route('security.index'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));

        $this->startNextRequest();
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_session_gets_a_new_id(): void
    {
        $sessionId = str_repeat('a', 40);

        $this->withCookie(config('session.cookie'), $sessionId);
        $this->confirmIdentity($this->user);

        $this->assertSame($sessionId, session()->getId());

        $this->changePassword();

        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_the_remember_token_rotates(): void
    {
        $this->confirmIdentity($this->user);

        $this->changePassword();

        $this->assertNotSame($this->user->remember_token, $this->user->fresh()->remember_token);
    }

    public function test_every_other_session_of_this_user_ends_and_nobody_elses(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($this->user, 'phone');
        $this->seedSession($this->user, 'tablet');
        $this->seedSession($other, 'not-mine');

        $this->confirmIdentity($this->user);
        $this->changePassword();

        $this->assertSame(['not-mine'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_the_given_session_is_the_one_that_survives(): void
    {
        $this->seedSession($this->user, 'keep');
        $this->seedSession($this->user, 'phone');

        $this->user->changePassword(self::NEW_PASSWORD, 'keep');

        $this->assertSame(['keep'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_a_stale_session_elsewhere_is_refused_by_its_password_hash(): void
    {
        $this->confirmIdentity($this->user);
        $this->get(route('dashboard'))->assertOk();
        $oldHash = session('password_hash_web');

        $this->changePassword();
        $this->startNextRequest();

        // What a row written back by a request in flight would hold.
        $this->session(['password_hash_web' => $oldHash]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_reset_link_requested_before_the_change_dies(): void
    {
        $token = Password::createToken($this->user);
        $otherUser = $this->enrolledUser();
        Password::createToken($otherUser);

        $this->confirmIdentity($this->user);
        $this->changePassword();

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $this->user->email)->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $otherUser->email)->count());
        $this->assertFalse(Password::tokenExists($this->user->fresh(), $token));
    }

    public function test_the_old_password_stops_working_at_login(): void
    {
        $this->confirmIdentity($this->user);
        $this->changePassword();

        $this->post(route('logout'));
        $this->startNextRequest();

        $this->submitPassword($this->user, 'password')->assertSessionHasErrors();
        $this->submitPassword($this->user, self::NEW_PASSWORD)->assertRedirect(route('two-factor.challenge'));
    }

    public function test_the_audit_entry_has_no_password_value(): void
    {
        $this->seedSession($this->user, 'phone');
        $this->confirmIdentity($this->user);

        $this->changePassword();

        $entry = AuditLog::where('event', AuditEvent::PasswordChanged->value)->sole();

        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertSame(['source' => 'security_settings', 'sessions_ended' => 1], $entry->metadata);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $entry->toJson());
        $this->assertStringNotContainsString($this->user->fresh()->password, $entry->toJson());
    }

    public function test_the_rules_come_from_account_rules(): void
    {
        $this->confirmIdentity($this->user);

        foreach (['short1A', 'alllowercase123', 'ALLUPPERCASE123', 'NoDigitsInThisOne'] as $weak) {
            $this->changePassword($weak)->assertSessionHasErrors('password');
        }

        $this->changePassword(self::NEW_PASSWORD, 'Something-Else-88')->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_the_current_password_cannot_be_kept(): void
    {
        $user = $this->enrolledUser(['password' => self::NEW_PASSWORD]);

        $this->post(route('logout'));
        $this->startNextRequest();
        $this->travel(30)->seconds();
        $this->submitPassword($user, self::NEW_PASSWORD);
        $this->submitCode($this->totp($user->two_factor_secret));
        $this->confirmIdentity($user, self::NEW_PASSWORD);

        $this->changePassword()->assertSessionHasErrors(['password' => 'The new password must be different from the current one.']);
        $this->assertSame(0, AuditLog::where('event', AuditEvent::PasswordChanged->value)->count());
    }

    public function test_a_password_is_never_flashed_back(): void
    {
        $this->confirmIdentity($this->user);

        $this->changePassword('short');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_nothing_is_sent_by_mail(): void
    {
        Mail::fake();
        $this->confirmIdentity($this->user);

        $this->changePassword();

        Mail::assertNothingSent();
    }
}
