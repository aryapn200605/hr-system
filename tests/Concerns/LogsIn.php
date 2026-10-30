<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

trait LogsIn
{
    /**
     * Create an active, enrolled user. Needs the seeded roles.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function enrolledUser(array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create($attributes);
    }

    /**
     * The authenticator code for a secret, offset from the current timestep.
     */
    protected function totp(string $secret, int $offset = 0): string
    {
        return (new Google2FA)->oathTotp($secret, (int) floor(now()->getTimestamp() / 30) + $offset);
    }

    /**
     * Pass the password stage.
     */
    protected function submitPassword(User $user, string $password = 'password'): TestResponse
    {
        return $this->from(route('login'))->post(route('login.store'), [
            'identifier' => $user->username,
            'password' => $password,
        ]);
    }

    /**
     * Submit an authenticator code on the challenge page.
     */
    protected function submitCode(string $code): TestResponse
    {
        return $this->from(route('two-factor.challenge'))->post(route('two-factor.challenge.store'), ['code' => $code]);
    }

    /**
     * Submit a recovery code on the challenge page.
     */
    protected function submitRecoveryCode(string $code): TestResponse
    {
        return $this->from(route('two-factor.challenge'))->post(route('two-factor.recovery.store'), ['recovery_code' => $code]);
    }

    /**
     * Go through both login stages with real requests.
     */
    protected function logIn(User $user): void
    {
        $this->submitPassword($user);
        $this->submitCode($this->totp($user->two_factor_secret))->assertRedirect(route('dashboard'));
    }

    /**
     * Earn a re-authentication proof with a real request. Time moves on first,
     * because the code that was used to log in is refused as a replay.
     */
    protected function confirmIdentity(User $user, string $password = 'password'): TestResponse
    {
        $this->travel(30)->seconds();

        return $this->from(route('security.confirm'))->post(route('security.confirm.store'), [
            'password' => $password,
            'code' => $this->totp($user->fresh()->two_factor_secret),
        ]);
    }

    /**
     * Assert that the last request was turned away by a rate limit.
     */
    protected function assertThrottled(): void
    {
        $this->assertStringContainsString('Too many attempts', json_encode(session('errors')));
    }

    /**
     * Drop the guard's in-memory user. Between real requests the guard starts empty
     * and reloads the user from the database, but a test shares one application.
     */
    protected function startNextRequest(): void
    {
        Auth::forgetGuards();
    }
}
