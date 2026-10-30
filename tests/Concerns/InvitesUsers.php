<?php

namespace Tests\Concerns;

use App\Actions\CreateUserWithInvitation;
use App\Actions\InvitationResult;
use App\Enums\RoleName;
use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Needs the seeded roles and the LogsIn trait.
 */
trait InvitesUsers
{
    protected const NEW_PASSWORD = 'Correct-Horse-42';

    /**
     * Create an active, enrolled HR Admin.
     */
    protected function hrAdmin(): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
    }

    /**
     * Invite a new employee through the real action.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function invite(array $overrides = []): InvitationResult
    {
        return app(CreateUserWithInvitation::class)->handle([
            'name' => 'Rina Wijaya',
            'username' => 'rina.w',
            'email' => 'rina@example.com',
            'role' => RoleName::Employee->value,
            ...$overrides,
        ], $this->hrAdmin());
    }

    /**
     * The plain token of the newest invitation email. Mail must be faked.
     */
    protected function lastInvitationToken(): string
    {
        return Str::afterLast(Mail::sent(InvitationMail::class)->last()->url, '/');
    }

    /**
     * Invite a user and return the user with the plain token. Mail must be faked.
     *
     * @return array{0: User, 1: string}
     */
    protected function invitedUser(): array
    {
        $user = $this->invite()->user;

        return [$user, $this->lastInvitationToken()];
    }

    /**
     * Open an invitation link.
     */
    protected function openLink(string $token): TestResponse
    {
        return $this->get(route('activation.start', $token));
    }

    /**
     * Submit the password step.
     */
    protected function submitActivationPassword(string $password = self::NEW_PASSWORD, ?string $confirmation = null): TestResponse
    {
        return $this->from(route('activation.password'))->post(route('activation.password.store'), [
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    /**
     * Read the unconfirmed secret off the 2FA page, as a user would type it.
     */
    protected function activationSecret(): string
    {
        $html = $this->get(route('activation.two-factor'))->assertOk()->getContent();

        preg_match('/font-mono[^>]*>([A-Z2-7 ]+)</', $html, $matches);

        return str_replace(' ', '', $matches[1]);
    }

    /**
     * Submit a code on the 2FA step.
     */
    protected function submitActivationCode(string $code): TestResponse
    {
        return $this->from(route('activation.two-factor'))->post(route('activation.two-factor.store'), ['code' => $code]);
    }

    /**
     * Go through a whole activation with real requests and return the final response.
     */
    protected function activate(string $token): TestResponse
    {
        $this->openLink($token);
        $this->submitActivationPassword();

        return $this->submitActivationCode($this->totp($this->activationSecret()));
    }
}
