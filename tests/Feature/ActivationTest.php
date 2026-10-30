<?php

namespace Tests\Feature;

use App\Actions\ResendInvitation;
use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuthThrottle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();
        Mail::fake();
    }

    private function assertNotActivated(User $user): void
    {
        $user = $user->fresh();

        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertNull($user->password);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_enrolled_at);
        $this->assertSame(0, $user->recoveryCodes()->count());
        $this->assertNull($user->invitations()->latest('id')->first()->accepted_at);
    }

    public function test_a_full_activation_activates_the_account(): void
    {
        [$user, $token] = $this->invitedUser();

        $response = $this->activate($token)->assertOk()->assertSee('Save your recovery codes')->assertSee('create a new set in Security Settings')->assertSee(route('login'));

        $user = $user->fresh();

        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($user->hasCompletedTwoFactorEnrollment());
        $this->assertSame(10, $user->recoveryCodes()->count());
        $this->assertNotNull($user->invitations()->sole()->accepted_at);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest();
        $this->assertFalse(session()->has('pending_activation'));
    }

    public function test_activation_writes_the_audit_trail(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->activate($token);

        foreach ([AuditEvent::InvitationConsumed, AuditEvent::PasswordChanged, AuditEvent::TwoFactorEnrolled] as $event) {
            $this->assertSame(1, AuditLog::where('event', $event->value)->where('subject_id', $user->id)->count(), $event->value);
        }

        $this->assertSame(['source' => 'activation'], AuditLog::where('event', AuditEvent::PasswordChanged->value)->sole()->metadata);
    }

    public function test_activation_is_not_a_login(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->activate($token);

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertSame(0, LoginHistory::count());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_activated_user_logs_in_with_the_new_password_and_a_new_code(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->activate($token);
        $user = $user->fresh();

        $this->submitPassword($user, self::NEW_PASSWORD)->assertRedirect(route('two-factor.challenge'));

        // The activation code's timestep is spent.
        $this->submitCode($this->totp($user->two_factor_secret))->assertSessionHasErrors('code');

        $this->travel(30)->seconds();
        $this->submitCode($this->totp($user->two_factor_secret))->assertRedirect(route('dashboard'));
    }

    public function test_opening_the_link_moves_to_a_url_without_the_token(): void
    {
        [, $token] = $this->invitedUser();

        $response = $this->openLink($token)->assertRedirect(route('activation.password'));

        $this->assertStringNotContainsString($token, $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, $this->get(route('activation.password'))->assertOk()->getContent());
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
    }

    public function test_opening_the_link_again_and_again_spends_nothing(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->openLink($token)->assertRedirect(route('activation.password'));

        $this->assertNotActivated($user);
    }

    public function test_opening_the_link_regenerates_the_session(): void
    {
        [, $token] = $this->invitedUser();

        $this->get(route('login'));
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before);

        $this->openLink($token);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_an_expired_token_is_refused(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->travel(config('auth.invitations.expire'))->minutes();

        $this->openLink($token)->assertStatus(410)->assertSee('not valid');

        $this->assertNotActivated($user);
    }

    public function test_a_used_token_is_refused(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->activate($token);
        $hash = $user->fresh()->password;

        $this->openLink($token)->assertStatus(410);

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_a_revoked_token_is_refused_and_the_new_one_works(): void
    {
        [$user, $oldToken] = $this->invitedUser();

        app(ResendInvitation::class)->handle($user, $this->hrAdmin());
        $newToken = $this->lastInvitationToken();

        $this->openLink($oldToken)->assertStatus(410);

        $this->activate($newToken)->assertOk();
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_an_unknown_token_gets_the_same_page_and_a_malformed_one_no_route(): void
    {
        $this->openLink(str_repeat('a', 64))->assertStatus(410)->assertSee('not valid');

        $this->get('/activation/short')->assertNotFound();
    }

    public function test_a_token_for_an_account_that_is_no_longer_pending_is_refused(): void
    {
        [$user, $token] = $this->invitedUser();

        // Directly, because suspend() also revokes the invitation and this covers the status check alone.
        User::whereKey($user->id)->update(['status' => UserStatus::Suspended]);

        $this->openLink($token)->assertRedirect(route('activation.password'));
        $this->get(route('activation.password'))->assertStatus(410);
    }

    public function test_suspending_a_pending_account_kills_its_link_at_once(): void
    {
        [$user, $token] = $this->invitedUser();

        $user->suspend();

        $this->openLink($token)->assertStatus(410);
        $this->assertNotNull($user->invitations()->sole()->revoked_at);
    }

    public function test_a_weak_password_is_refused_and_keeps_the_step(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);

        foreach (['short1A', 'alllowercase12345', 'NoDigitsInThisOne'] as $weak) {
            $this->submitActivationPassword($weak)
                ->assertRedirect(route('activation.password'))
                ->assertSessionHasErrors('password');
        }

        $this->assertNull(session('pending_activation.password'));
        $this->get(route('activation.two-factor'))->assertRedirect(route('activation.password'));
        $this->assertNotActivated($user);
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);

        $this->submitActivationPassword(self::NEW_PASSWORD, 'Different-Horse-42')->assertSessionHasErrors('password');
    }

    public function test_the_password_is_kept_hashed_and_encrypted_until_the_last_step(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        $stored = session('pending_activation.password');

        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode(session()->all()));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, Crypt::decryptString($stored)));
        $this->assertNotActivated($user);
    }

    public function test_a_wrong_code_changes_nothing_and_a_right_one_still_works(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();
        $secret = $this->activationSecret();

        $this->submitActivationCode($this->totp($secret, 5))
            ->assertRedirect(route('activation.two-factor'))
            ->assertSessionHasErrors('code');
        $this->submitActivationCode('12345')->assertSessionHasErrors('code');

        $this->assertNotActivated($user);

        $this->submitActivationCode($this->totp($secret))->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_the_secret_stays_the_same_across_reloads(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        $this->assertSame($this->activationSecret(), $this->activationSecret());
    }

    public function test_the_qr_code_is_rendered_locally(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        $html = $this->get(route('activation.two-factor'))->assertSee('<svg', false)->getContent();

        $this->assertDoesNotMatchRegularExpression('/<img[^>]+https?:/', $html);
    }

    public function test_two_factor_cannot_be_skipped_or_reached_out_of_order(): void
    {
        [$user, $token] = $this->invitedUser();

        foreach ([
            fn () => $this->get(route('activation.password')),
            fn () => $this->get(route('activation.two-factor')),
            fn () => $this->post(route('activation.password.store'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]),
            fn () => $this->post(route('activation.two-factor.store'), ['code' => '123456']),
        ] as $withoutLink) {
            $withoutLink()->assertStatus(410);
        }

        $this->openLink($token);

        $this->get(route('activation.two-factor'))->assertRedirect(route('activation.password'));
        $this->post(route('activation.two-factor.store'), ['code' => '123456'])->assertRedirect(route('activation.password'));

        // A pending account has no way in through the login form either.
        $this->post(route('login.store'), ['identifier' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('identifier');

        $this->assertNotActivated($user);
    }

    public function test_every_step_stops_when_the_invitation_is_revoked_meanwhile(): void
    {
        [$user, $token] = $this->invitedUser();
        $invitation = $user->invitations()->sole();

        $this->openLink($token);
        $this->get(route('activation.password'))->assertOk();

        $invitation->update(['revoked_at' => now()]);

        $this->get(route('activation.password'))->assertStatus(410);
        $this->assertFalse(session()->has('pending_activation'));
    }

    public function test_the_password_post_stops_when_the_invitation_expires_meanwhile(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);

        $user->invitations()->sole()->update(['expires_at' => now()->subSecond()]);

        $this->submitActivationPassword()->assertStatus(410);
        $this->assertNotActivated($user);
    }

    public function test_the_two_factor_page_stops_when_the_account_is_suspended_meanwhile(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        $user->suspend();

        $this->get(route('activation.two-factor'))->assertStatus(410);
    }

    public function test_the_last_step_stops_when_the_invitation_is_replaced_meanwhile(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();
        $secret = $this->activationSecret();

        app(ResendInvitation::class)->handle($user, $this->hrAdmin());

        $this->submitActivationCode($this->totp($secret))->assertStatus(410);

        $this->assertSame(UserStatus::Pending, $user->fresh()->status);
        $this->assertNull($user->fresh()->password);
    }

    public function test_the_pending_state_expires(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);

        $this->travel(1801)->seconds();

        $this->get(route('activation.password'))->assertStatus(410);
    }

    public function test_the_final_write_rechecks_the_invitation_after_a_stale_read(): void
    {
        [$user] = $this->invitedUser();

        $stale = $user->invitations()->sole();
        AccountInvitation::whereKey($stale->id)->update(['revoked_at' => now()]);

        $this->assertNull($user->completeActivation($stale, Hash::make('x'), 'SECRETSECRETSECRET', 1));
        $this->assertNotActivated($user);
    }

    public function test_the_final_write_rechecks_the_account_after_a_stale_read(): void
    {
        [$user] = $this->invitedUser();

        $stale = User::find($user->id);
        $user->suspend();

        $this->assertNull($stale->completeActivation($user->invitations()->sole(), Hash::make('x'), 'SECRETSECRETSECRET', 1));
        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
        $this->assertNull($user->invitations()->sole()->accepted_at);
    }

    public function test_a_failure_during_activation_rolls_everything_back(): void
    {
        [$user] = $this->invitedUser();

        AuditLog::creating(function (AuditLog $audit) {
            if ($audit->event === AuditEvent::PasswordChanged->value) {
                throw new RuntimeException('Disk full.');
            }
        });

        try {
            $user->completeActivation($user->invitations()->sole(), Hash::make('x'), 'SECRETSECRETSECRET', 1);

            $this->fail('The failure should have surfaced.');
        } catch (RuntimeException) {
            $this->assertNotActivated($user);
            $this->assertSame(0, AuditLog::where('event', AuditEvent::InvitationConsumed->value)->count());
        }
    }

    public function test_a_logged_in_browser_stays_logged_in_and_the_link_is_not_opened(): void
    {
        [$user, $token] = $this->invitedUser();
        $other = $this->enrolledUser();
        $this->logIn($other);

        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $sessionId);

        $this->openLink($token)->assertStatus(409)->assertSee('logged in to another account')->assertSee(route('logout'));

        $this->assertAuthenticatedAs($other);
        $this->assertSame($sessionId, session()->getId());
        $this->assertFalse(session()->has('pending_activation'));
        $this->assertNotActivated($user);
    }

    public function test_the_logged_in_page_reveals_nothing_about_the_token(): void
    {
        $this->logIn($this->enrolledUser());

        $this->openLink(str_repeat('a', 64))->assertStatus(409);
    }

    public function test_activation_pages_stay_closed_to_a_logged_in_browser(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();
        $secret = $this->activationSecret();

        // Another tab logs in as someone else, in the same session.
        $other = $this->enrolledUser();
        $this->logIn($other);

        $this->post(route('activation.two-factor.store'), ['code' => $this->totp($secret)])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($other);
        $this->assertFalse(session()->has('pending_activation'));
        $this->assertNotActivated($user);
    }

    public function test_after_logging_out_the_same_link_works_in_a_clean_session(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->logIn($this->enrolledUser());
        $this->openLink($token)->assertStatus(409);

        $this->post(route('logout'));
        $this->startNextRequest();

        $this->activate($token)->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertGuest();
    }

    public function test_opening_a_link_drops_a_login_that_was_half_way(): void
    {
        [, $token] = $this->invitedUser();

        $this->submitPassword($this->enrolledUser());
        $this->assertTrue(session()->has('pending_login'));

        $this->openLink($token);

        $this->assertFalse(session()->has('pending_login'));
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_starting_a_login_drops_an_activation_that_was_half_way(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        $this->submitPassword($this->enrolledUser());

        $this->assertFalse(session()->has('pending_activation'));
        $this->get(route('activation.two-factor'))->assertStatus(410);
    }

    public function test_every_activation_route_is_throttled_per_ip_including_the_code(): void
    {
        [, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();

        // Every code here is valid in form and wrong in value, so only the page limit stops them.
        $attempts = AuthThrottle::ACTIVATION_REQUESTS_PER_MINUTE - 2;
        foreach (range(1, $attempts) as $attempt) {
            $this->get(route('activation.two-factor'))->assertOk();
        }

        $this->post(route('activation.two-factor.store'), ['code' => '000000'])->assertTooManyRequests();
        $this->get(route('activation.two-factor'))->assertTooManyRequests();
        $this->openLink($token)->assertTooManyRequests();

        // Another address has its own bucket.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9']);
        $this->get(route('activation.two-factor'))->assertOk();
    }

    public function test_a_flood_of_bad_links_is_throttled_per_ip(): void
    {
        foreach (range(1, AuthThrottle::ACTIVATION_REQUESTS_PER_MINUTE) as $attempt) {
            $this->openLink(hash('sha256', (string) $attempt))->assertStatus(410);
        }

        $this->openLink(str_repeat('b', 64))->assertTooManyRequests();
    }

    public function test_wrong_codes_are_throttled_in_the_shared_two_factor_bucket(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLink($token);
        $this->submitActivationPassword();
        $secret = $this->activationSecret();

        foreach (range(1, 5) as $attempt) {
            $this->submitActivationCode($this->totp($secret, 5))->assertSessionHasErrors('code');
        }

        $this->submitActivationCode($this->totp($secret));

        $this->assertThrottled();
        $this->assertNotActivated($user);
    }

    public function test_throttle_keys_hold_no_readable_address(): void
    {
        $request = request()->create('/', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertStringNotContainsString('203.0.113.7', AuthThrottle::activationKey($request));
        $this->assertStringNotContainsString('203.0.113.7', AuthThrottle::resetPasswordKey($request));
        $this->assertStringNotContainsString('rina@example.com', AuthThrottle::forgotPasswordKey($request, 'rina@example.com'));
    }
}
