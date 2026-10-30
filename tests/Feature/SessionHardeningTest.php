<?php

namespace Tests\Feature;

use App\Enums\LoginResult;
use App\Enums\RoleName;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use stdClass;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class SessionHardeningProbe extends Component
{
    public static int $calls = 0;

    public function touch(): void
    {
        static::$calls++;
    }

    public function render(): string
    {
        return '<div>probe</div>';
    }
}

/**
 * A password change must reach logins in progress and sessions that survived the row delete.
 */
class SessionHardeningTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();
    }

    public function test_a_password_change_cancels_a_login_waiting_for_the_challenge(): void
    {
        $user = $this->enrolledUser();

        $this->submitPassword($user)->assertRedirect(route('two-factor.challenge'));

        $user->resetPassword('Brand-New-Horse-77');

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->assertFalse(session()->has('pending_login'));
        $this->assertGuest();
    }

    public function test_a_valid_code_after_a_password_change_does_not_finish_the_login(): void
    {
        $user = $this->enrolledUser();

        $this->submitPassword($user);

        $user->resetPassword('Brand-New-Horse-77');

        $this->submitCode($this->totp($user->two_factor_secret))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, LoginHistory::where('result', LoginResult::Success)->count());
    }

    public function test_a_password_change_cancels_a_login_waiting_for_a_recovery_code(): void
    {
        $user = $this->enrolledUser();
        $codes = RecoveryCode::regenerateFor($user);

        $this->submitPassword($user);

        $user->resetPassword('Brand-New-Horse-77');

        $this->submitRecoveryCode($codes[0])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, $user->recoveryCodes()->whereNotNull('used_at')->count());
    }

    public function test_a_password_change_cancels_a_login_in_forced_enrollment(): void
    {
        $user = User::factory()->withRole(RoleName::HrAdmin)->create();

        $this->submitPassword($user)->assertRedirect(route('two-factor.enrollment'));
        $this->get(route('two-factor.enrollment'))->assertOk();

        $user->resetPassword('Brand-New-Horse-77');

        $this->get(route('two-factor.enrollment'))->assertRedirect(route('login'));
        $this->post(route('two-factor.enrollment.store'), ['code' => '123456'])->assertRedirect(route('login'));

        $this->assertFalse($user->fresh()->hasCompletedTwoFactorEnrollment());
    }

    public function test_an_old_pending_state_without_the_fingerprint_is_refused(): void
    {
        $user = $this->enrolledUser();

        $this->session(['pending_login' => ['user_id' => $user->id, 'expires_at' => now()->addMinute()->getTimestamp()]]);

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_an_unchanged_password_keeps_the_pending_login(): void
    {
        $user = $this->enrolledUser();

        $this->submitPassword($user);

        $user->forceFill(['name' => 'Someone Else'])->save();

        $this->get(route('two-factor.challenge'))->assertOk();
    }

    public function test_a_password_change_ends_a_session_at_its_next_request(): void
    {
        $user = $this->enrolledUser();
        $this->logIn($user);
        $this->get(route('dashboard'))->assertOk();

        $user->resetPassword('Brand-New-Horse-77');
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_password_change_ends_a_session_before_its_first_page(): void
    {
        $user = $this->enrolledUser();
        $this->logIn($user);

        $user->resetPassword('Brand-New-Horse-77');
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_session_survives_other_changes_to_the_user(): void
    {
        $user = $this->enrolledUser();
        $this->logIn($user);

        $user->forceFill(['name' => 'Someone Else'])->save();
        $this->startNextRequest();

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_password_change_refuses_livewire_actions_with_a_401(): void
    {
        SessionHardeningProbe::$calls = 0;
        Livewire::component('session-hardening-probe', SessionHardeningProbe::class);
        Route::middleware(['web', 'fully.authenticated'])
            ->get('/test-probe', fn () => Blade::render('<livewire:session-hardening-probe />'));

        $user = $this->enrolledUser();
        $this->logIn($user);

        preg_match('/wire:snapshot="([^"]+)"/', $this->get('/test-probe')->getContent(), $matches);

        $user->resetPassword('Brand-New-Horse-77');
        $this->startNextRequest();

        $this->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1]),
                'updates' => new stdClass,
                'calls' => [['method' => 'touch', 'params' => [], 'metadata' => new stdClass]],
            ]],
        ], ['X-Livewire' => '1'])->assertUnauthorized();

        $this->assertSame(0, SessionHardeningProbe::$calls);
    }

    public function test_the_auth_session_middleware_guards_the_full_login_routes(): void
    {
        foreach (['dashboard', 'logout'] as $name) {
            $this->assertContains('auth.session', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $update = Route::getRoutes()->match(request()->create(Livewire::getUpdateUri(), 'POST'));

        $this->assertContains('auth.session', $update->gatherMiddleware());
    }
}
