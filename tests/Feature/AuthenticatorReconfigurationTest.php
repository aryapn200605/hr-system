<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Support\AuthThrottle;
use App\Support\Reauthentication;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LogsIn;
use Tests\Concerns\SeedsSessions;
use Tests\TestCase;

class AuthenticatorReconfigurationTest extends TestCase
{
    use LogsIn, RefreshDatabase, SeedsSessions;

    private User $user;

    private string $oldSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->oldSecret = $this->user->two_factor_secret;
        $this->logIn($this->user);
        $this->confirmIdentity($this->user);
    }

    /**
     * Open the page and read the new secret from it.
     */
    private function newSecret(): string
    {
        $html = $this->get(route('security.authenticator'))->assertOk()->getContent();

        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);

        return str_replace(' ', '', $matches[1]);
    }

    private function submit(string $code): TestResponse
    {
        $this->startNextRequest();

        return $this->from(route('security.authenticator'))->post(route('security.authenticator.store'), ['code' => $code]);
    }

    public function test_the_page_shows_a_new_secret_and_never_the_stored_one(): void
    {
        $response = $this->get(route('security.authenticator'))->assertOk();

        $this->assertStringNotContainsString($this->oldSecret, $response->getContent());
        $this->assertStringNotContainsString(implode(' ', str_split($this->oldSecret, 4)), $response->getContent());
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertNotSame($this->oldSecret, $this->newSecret());
    }

    public function test_opening_the_page_changes_nothing_in_the_database(): void
    {
        $before = $this->user->fresh()->getAttributes();

        $this->newSecret();

        $this->assertSame($before, $this->user->fresh()->getAttributes());
    }

    public function test_the_new_secret_waits_encrypted_in_the_session_and_stays_the_same_on_reload(): void
    {
        $secret = $this->newSecret();

        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->assertSame($secret, Crypt::decryptString(session('reconfigure_two_factor_secret')));
        $this->assertSame($secret, $this->newSecret());
    }

    public function test_a_valid_code_swaps_the_secret_and_stores_its_timestep(): void
    {
        $secret = $this->newSecret();
        $timestep = (int) floor(now()->getTimestamp() / 30) - 1;

        $this->submit($this->totp($secret, -1))->assertOk()->assertSee('Save your recovery codes');

        $fresh = $this->user->fresh();

        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertSame($timestep, $fresh->two_factor_last_timestep);
        $this->assertTrue($fresh->hasCompletedTwoFactorEnrollment());
        $this->assertFalse(session()->has('reconfigure_two_factor_secret'));
    }

    public function test_the_stored_timestep_ignores_a_newer_one_from_the_old_secret(): void
    {
        $this->user->forceFill(['two_factor_last_timestep' => (int) floor(now()->getTimestamp() / 30) + 1])->save();

        $secret = $this->newSecret();
        $timestep = (int) floor(now()->getTimestamp() / 30);

        $this->submit($this->totp($secret))->assertOk();

        $this->assertSame($timestep, $this->user->fresh()->two_factor_last_timestep);
    }

    public function test_the_confirmation_code_cannot_be_replayed_at_login(): void
    {
        $secret = $this->newSecret();
        $code = $this->totp($secret);

        $this->submit($code)->assertOk();
        $this->post(route('logout'));
        $this->startNextRequest();
        $this->submitPassword($this->user);

        $this->submitCode($code)->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_after_the_swap_only_the_new_authenticator_logs_in(): void
    {
        $secret = $this->newSecret();
        $this->submit($this->totp($secret))->assertOk();

        $this->post(route('logout'));
        $this->startNextRequest();
        $this->travel(30)->seconds();
        $this->submitPassword($this->user);

        $this->submitCode($this->totp($this->oldSecret))->assertSessionHasErrors('code');
        $this->submitCode($this->totp($secret))->assertRedirect(route('dashboard'));
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $oldCodes = RecoveryCode::regenerateFor($this->user);
        $secret = $this->newSecret();
        $this->seedSession($this->user, 'phone');
        $before = $this->user->fresh()->getAttributes();

        $this->submit('000000')
            ->assertRedirect(route('security.authenticator'))
            ->assertSessionHasErrors(['code' => 'The code is invalid.']);

        $this->assertSame($before, $this->user->fresh()->getAttributes());
        $this->assertSame(1, DB::table('sessions')->where('id', 'phone')->count());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::TwoFactorReconfigured->value)->count());
        $this->assertSame(10, $this->user->recoveryCodes()->whereNull('used_at')->count());
        $this->assertTrue(RecoveryCode::consume($this->user, $oldCodes[0]));

        // The attempt can be repeated with the same secret and the same proof.
        $this->assertSame($secret, $this->newSecret());
    }

    public function test_a_code_from_the_old_secret_is_refused(): void
    {
        $this->newSecret();
        $before = $this->user->fresh()->getAttributes();

        $this->submit($this->totp($this->oldSecret))->assertSessionHasErrors('code');

        $this->assertSame($before, $this->user->fresh()->getAttributes());
    }

    public function test_a_failed_save_leaves_the_old_secret_and_codes_in_place(): void
    {
        $oldCodes = RecoveryCode::regenerateFor($this->user);
        $secret = $this->newSecret();

        // Fails after the secret and new codes are written, so everything must roll back.
        AuditLog::creating(function (AuditLog $entry) {
            if ($entry->event === AuditEvent::TwoFactorReconfigured->value) {
                throw new \RuntimeException('Disk full.');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->submit($this->totp($secret));
            $this->fail('The failure did not surface.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($this->oldSecret, $this->user->fresh()->two_factor_secret);
        $this->assertTrue(RecoveryCode::consume($this->user, $oldCodes[0]));
    }

    public function test_old_recovery_codes_die_and_new_ones_appear_once(): void
    {
        $oldCodes = RecoveryCode::regenerateFor($this->user);
        $secret = $this->newSecret();

        $response = $this->submit($this->totp($secret))->assertOk();

        preg_match_all('/<li>([A-Z2-9-]{19})<\/li>/', $response->getContent(), $matches);

        $this->assertCount(10, $matches[1]);
        $this->assertFalse(RecoveryCode::consume($this->user, $oldCodes[0]));
        $this->assertTrue(RecoveryCode::consume($this->user, $matches[1][0]));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($matches[1][1], json_encode(session()->all()));
    }

    public function test_every_other_session_ends_and_the_audit_entry_counts_them(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($this->user, 'phone');
        $this->seedSession($this->user, 'tablet');
        $this->seedSession($other, 'not-mine');
        $secret = $this->newSecret();

        $this->submit($this->totp($secret))->assertOk();

        $this->assertSame(['not-mine'], DB::table('sessions')->pluck('id')->all());

        $entry = AuditLog::where('event', AuditEvent::TwoFactorReconfigured->value)->sole();

        $this->assertSame(['sessions_ended' => 2], $entry->metadata);
        $this->assertSame($this->user->id, $entry->actor_id);
    }

    public function test_the_given_session_is_the_one_that_survives(): void
    {
        $this->seedSession($this->user, 'keep');
        $this->seedSession($this->user, 'phone');

        $this->user->reconfigureTwoFactor('JBSWY3DPEHPK3PXP', 1, 'keep');

        $this->assertSame(['keep'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_the_current_session_stays_logged_in(): void
    {
        $this->submit($this->totp($this->newSecret()))->assertOk();
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_an_account_that_lost_eligibility_is_left_alone(): void
    {
        $this->user->suspend();

        $this->assertNull($this->user->fresh()->reconfigureTwoFactor('JBSWY3DPEHPK3PXP', 1, 'keep'));

        $this->assertSame($this->oldSecret, $this->user->fresh()->two_factor_secret);
    }

    public function test_the_attempts_share_the_two_factor_bucket(): void
    {
        $secret = $this->newSecret();

        foreach (range(1, 5) as $attempt) {
            $this->submit('000000');
        }

        $this->submit($this->totp($secret));

        $this->assertThrottled();
        $this->assertSame($this->oldSecret, $this->user->fresh()->two_factor_secret);
    }

    public function test_an_expired_proof_stops_the_swap_and_keeps_the_scanned_secret(): void
    {
        $secret = $this->newSecret();

        $this->travel(Reauthentication::TTL_SECONDS + 1)->seconds();

        $this->submit($this->totp($secret))->assertRedirect(route('security.confirm'));
        $this->assertSame($this->oldSecret, $this->user->fresh()->two_factor_secret);

        $this->confirmIdentity($this->user)->assertRedirect(route('security.authenticator'));

        $this->assertSame($secret, $this->newSecret());
    }

    public function test_cancelling_drops_the_secret_and_leaves_the_account_alone(): void
    {
        $secret = $this->newSecret();

        $this->post(route('security.authenticator.cancel'))->assertRedirect(route('security.index'));

        $this->assertFalse(session()->has('reconfigure_two_factor_secret'));
        $this->assertSame($this->oldSecret, $this->user->fresh()->two_factor_secret);
        $this->assertNotSame($secret, $this->newSecret());
    }

    public function test_a_completed_swap_clears_the_bucket(): void
    {
        $key = AuthThrottle::twoFactorKey(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']), $this->user);
        $secret = $this->newSecret();

        $this->submit('000000');

        $this->assertSame(1, RateLimiter::attempts($key));

        $this->submit($this->totp($secret))->assertOk();

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_a_completed_swap_spends_the_proof(): void
    {
        $this->submit($this->totp($this->newSecret()))->assertOk();
        $this->startNextRequest();

        $this->get(route('security.authenticator'))->assertRedirect(route('security.confirm'));
    }

    public function test_the_page_is_never_cached(): void
    {
        $header = $this->get(route('security.authenticator'))->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $header);
    }

    public function test_the_code_format_is_validated(): void
    {
        $this->newSecret();

        $this->submit('12345')->assertSessionHasErrors('code');
        $this->submit('abcdef')->assertSessionHasErrors('code');
        $this->assertNull(session()->getOldInput('code'));
    }
}
