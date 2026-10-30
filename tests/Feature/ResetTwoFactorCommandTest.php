<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\LogsIn;
use Tests\Concerns\SeedsSessions;
use Tests\TestCase;

class ResetTwoFactorCommandTest extends TestCase
{
    use LogsIn, RefreshDatabase, SeedsSessions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        RecoveryCode::regenerateFor($this->user);
        $this->user->forceFill(['two_factor_last_timestep' => 123])->save();
    }

    private function runCommand(?string $username = null): PendingCommand
    {
        return $this->artisan('app:reset-two-factor', ['username' => $username ?? $this->user->username]);
    }

    private function confirmed(?string $username = null): PendingCommand
    {
        return $this->runCommand($username)->expectsConfirmation('Reset 2FA for '.($username ?? $this->user->username).'?', 'yes');
    }

    public function test_it_clears_the_secret_enrollment_timestep_and_recovery_codes(): void
    {
        $this->confirmed()->assertSuccessful();

        $fresh = $this->user->fresh();

        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_enrolled_at);
        $this->assertNull($fresh->two_factor_last_timestep);
        $this->assertSame(0, $fresh->recoveryCodes()->count());
        $this->assertFalse($fresh->hasCompletedTwoFactorEnrollment());
    }

    public function test_it_keeps_the_password_status_and_role(): void
    {
        $this->confirmed()->assertSuccessful();

        $fresh = $this->user->fresh();

        $this->assertSame($this->user->password, $fresh->password);
        $this->assertSame($this->user->status, $fresh->status);
        $this->assertSame($this->user->roleName(), $fresh->roleName());
    }

    public function test_it_ends_every_session_of_that_user_and_nobody_elses(): void
    {
        $other = $this->enrolledUser();
        $this->seedSession($this->user, 'one');
        $this->seedSession($this->user, 'two');
        $this->seedSession($other, 'not-mine');

        $this->confirmed()->assertSuccessful();

        $this->assertSame(['not-mine'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_it_writes_an_audit_entry_with_a_null_actor(): void
    {
        $this->seedSession($this->user, 'one');

        $this->confirmed()->assertSuccessful();

        $entry = AuditLog::where('event', AuditEvent::TwoFactorReset->value)->sole();

        $this->assertNull($entry->actor_id);
        $this->assertSame($this->user->id, $entry->subject_id);
        $this->assertSame(1, $entry->metadata['sessions_ended']);
        $this->assertEqualsCanonicalizing(['sessions_ended', 'operator'], array_keys($entry->metadata));
    }

    public function test_the_audit_entry_names_the_operating_system_account_and_nothing_secret(): void
    {
        $saved = [getenv('USERNAME'), getenv('USER')];
        putenv('USERNAME=ops.person');
        putenv('USER');

        try {
            $this->confirmed()->assertSuccessful();
        } finally {
            putenv($saved[0] === false ? 'USERNAME' : 'USERNAME='.$saved[0]);
            putenv($saved[1] === false ? 'USER' : 'USER='.$saved[1]);
        }

        $entry = AuditLog::where('event', AuditEvent::TwoFactorReset->value)->sole();

        $this->assertSame('ops.person@'.gethostname(), $entry->metadata['operator']);
        $this->assertStringNotContainsString((string) $this->user->getRawOriginal('two_factor_secret'), (string) json_encode($entry->metadata));
    }

    public function test_the_next_login_forces_enrollment(): void
    {
        $this->confirmed()->assertSuccessful();

        $this->submitPassword($this->user)->assertRedirect(route('two-factor.enrollment'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->submitPassword($this->user);
        $this->get(route('two-factor.enrollment'))->assertOk()->assertSee('Set up two-factor authentication');
    }

    public function test_a_login_in_progress_moves_to_enrollment_and_cannot_use_the_old_code(): void
    {
        $code = $this->totp($this->user->two_factor_secret);

        $this->submitPassword($this->user)->assertRedirect(route('two-factor.challenge'));

        $this->confirmed()->assertSuccessful();

        $this->submitCode($code)->assertRedirect(route('two-factor.enrollment'));
        $this->assertGuest();
    }

    public function test_a_logged_in_user_is_turned_away_at_the_next_request(): void
    {
        $this->logIn($this->user);
        $this->get(route('dashboard'))->assertOk();

        $this->confirmed()->assertSuccessful();
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unknown_username_is_refused(): void
    {
        $this->runCommand('nobody.here')
            ->expectsOutputToContain('No user has that username.')
            ->assertFailed();

        $this->assertSame(0, AuditLog::where('event', AuditEvent::TwoFactorReset->value)->count());
    }

    public function test_refusing_the_confirmation_changes_nothing(): void
    {
        $this->seedSession($this->user, 'one');
        $before = $this->user->fresh()->getAttributes();

        $this->runCommand()
            ->expectsConfirmation('Reset 2FA for '.$this->user->username.'?', 'no')
            ->expectsOutputToContain('Cancelled. Nothing changed.')
            ->assertFailed();

        $this->assertSame($before, $this->user->fresh()->getAttributes());
        $this->assertSame(10, $this->user->recoveryCodes()->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'one')->count());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::TwoFactorReset->value)->count());
    }

    public function test_without_a_terminal_the_answer_is_no(): void
    {
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('app:reset-two-factor', [
            'username' => $this->user->username,
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertNotNull($this->user->fresh()->two_factor_secret);
    }

    public function test_an_account_without_2fa_is_refused(): void
    {
        $pending = User::factory()->pending()->create();

        $this->runCommand($pending->username)
            ->expectsOutputToContain('has no 2FA to reset')
            ->assertFailed();

        $this->assertSame(0, AuditLog::where('event', AuditEvent::TwoFactorReset->value)->count());
    }

    public function test_a_second_reset_finds_nothing_to_do(): void
    {
        $stale = $this->user->fresh();

        $this->assertTrue($this->user->resetTwoFactor());
        $this->assertFalse($stale->resetTwoFactor());

        $this->assertSame(1, AuditLog::where('event', AuditEvent::TwoFactorReset->value)->count());
    }

    public function test_the_username_is_matched_in_any_case(): void
    {
        $upper = strtoupper($this->user->username);

        $this->runCommand($upper)
            ->expectsConfirmation('Reset 2FA for '.$this->user->username.'?', 'yes')
            ->assertSuccessful();

        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_it_works_on_a_secret_that_can_no_longer_be_decrypted(): void
    {
        // What every secret looks like after APP_KEY rotates.
        DB::table('users')->where('id', $this->user->id)->update(['two_factor_secret' => 'encrypted-under-an-old-key']);

        $this->confirmed()->assertSuccessful();

        $this->assertNull(DB::table('users')->where('id', $this->user->id)->value('two_factor_secret'));
    }

    public function test_the_output_never_holds_the_secret(): void
    {
        $this->confirmed()
            ->doesntExpectOutputToContain($this->user->two_factor_secret)
            ->assertSuccessful();
    }

    public function test_it_is_console_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

        $this->assertEmpty($routes->filter(fn (string $uri) => str_contains($uri, 'reset-two-factor')));
    }
}
