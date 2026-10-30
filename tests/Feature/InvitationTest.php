<?php

namespace Tests\Feature;

use App\Actions\CreateUserWithInvitation;
use App\Actions\ResendInvitation;
use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Mail\InvitationMail;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    /**
     * Make the real SMTP transport fail: nothing listens on this port.
     */
    private function breakTransport(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        Mail::swap(new MailManager($this->app));
    }

    private function auditCount(AuditEvent $event): int
    {
        return AuditLog::where('event', $event->value)->count();
    }

    public function test_it_creates_a_pending_user_with_a_role_and_an_invitation(): void
    {
        $this->freezeSecond();

        $result = $this->invite(['username' => 'Rina.W', 'email' => 'Rina@Example.com']);

        $user = $result->user->fresh();

        $this->assertTrue($result->sent);
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertSame('rina.w', $user->username);
        $this->assertSame('rina@example.com', $user->email);
        $this->assertNull($user->password);
        $this->assertFalse($user->hasCompletedTwoFactorEnrollment());
        $this->assertTrue($user->hasRole(RoleName::Employee->value));
        $this->assertSame(1, $user->invitations()->count());
        $this->assertTrue($result->invitation->fresh()->sent_at->equalTo(now()));
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        [$user, $token] = $this->invitedUser();

        $invitation = $user->invitations()->sole();

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
    }

    public function test_tokens_differ_between_invitations(): void
    {
        [, $first] = $this->invitedUser();

        $this->invite(['username' => 'budi.s', 'email' => 'budi@example.com']);

        $this->assertNotSame($first, $this->lastInvitationToken());
    }

    public function test_the_expiry_is_configurable(): void
    {
        $this->freezeSecond();
        config(['auth.invitations.expire' => 90]);

        $result = $this->invite();

        $this->assertTrue($result->invitation->expires_at->equalTo(now()->addMinutes(90)));
    }

    public function test_it_writes_the_audit_trail_with_the_actor(): void
    {
        $admin = $this->hrAdmin();

        $result = app(CreateUserWithInvitation::class)->handle([
            'name' => 'Rina Wijaya',
            'username' => 'rina.w',
            'email' => 'rina@example.com',
            'role' => RoleName::Employee->value,
        ], $admin);

        foreach ([AuditEvent::UserCreated, AuditEvent::InvitationCreated, AuditEvent::UserRoleChanged] as $event) {
            $audit = AuditLog::where('event', $event->value)->where('subject_id', $result->user->id)->sole();

            $this->assertSame($admin->id, $audit->actor_id);
        }
    }

    public function test_the_email_is_sent_synchronously_to_the_user(): void
    {
        $this->invite();

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('rina@example.com') && ! $mail->resent);
        Mail::assertNothingQueued();
    }

    public function test_the_email_is_responsive_html_with_the_link_and_a_text_part(): void
    {
        $mail = new InvitationMail('Rina Wijaya', str_repeat('a', 64));
        $html = $mail->render();

        $this->assertStringContainsString('<!DOCTYPE html', $html);
        $this->assertStringContainsString('@media', $html);
        $this->assertStringContainsString($mail->url, $html);
        $this->assertStringNotContainsString('src="http', $html);
        $mail->assertSeeInText($mail->url);
    }

    public function test_invalid_input_creates_nothing(): void
    {
        $admin = $this->hrAdmin();
        $before = User::count();

        foreach ([
            ['name' => ''],
            ['username' => 'no spaces allowed'],
            ['email' => 'not-an-email'],
            ['role' => 'Superuser'],
        ] as $invalid) {
            try {
                app(CreateUserWithInvitation::class)->handle([
                    'name' => 'Rina Wijaya',
                    'username' => 'rina.w',
                    'email' => 'rina@example.com',
                    'role' => RoleName::Employee->value,
                    ...$invalid,
                ], $admin);

                $this->fail('Validation should have failed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($invalid), $exception->errors());
            }
        }

        $this->assertSame($before, User::count());
        $this->assertSame(0, AccountInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_a_duplicate_username_or_email_is_refused(): void
    {
        $this->invite();

        $this->expectException(ValidationException::class);

        $this->invite(['username' => 'other.user']);
    }

    public function test_a_user_without_the_permissions_cannot_create_or_invite(): void
    {
        $employee = $this->enrolledUser();
        $before = User::count();

        $this->expectException(AuthorizationException::class);

        try {
            app(CreateUserWithInvitation::class)->handle([
                'name' => 'Rina Wijaya',
                'username' => 'rina.w',
                'email' => 'rina@example.com',
                'role' => RoleName::HrAdmin->value,
            ], $employee);
        } finally {
            $this->assertSame($before, User::count());
            Mail::assertNothingSent();
        }
    }

    public function test_a_failed_transport_is_reported_and_keeps_the_user_and_invitation(): void
    {
        $this->breakTransport();

        $result = $this->invite();

        $this->assertFalse($result->sent);
        $this->assertSame(UserStatus::Pending, $result->user->fresh()->status);
        $this->assertNull($result->invitation->fresh()->sent_at);
        $this->assertNotNull(AccountInvitation::query()->open()->find($result->invitation->id));
        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationDeliveryFailed));
    }

    public function test_an_invitation_can_be_resent_after_a_failed_transport(): void
    {
        $this->breakTransport();
        $user = $this->invite()->user;

        Mail::fake();

        $result = app(ResendInvitation::class)->handle($user, $this->hrAdmin());

        $this->assertTrue($result->sent);
        $this->assertNotNull($result->invitation->fresh()->sent_at);
        Mail::assertSent(InvitationMail::class, 1);
    }

    public function test_a_local_mailer_in_production_counts_as_a_failure(): void
    {
        config(['mail.default' => 'log']);
        Mail::swap(new MailManager($this->app));
        $this->app['env'] = 'production';

        $result = $this->invite();

        $this->assertFalse($result->sent);
        $this->assertNull($result->invitation->fresh()->sent_at);
    }

    public function test_resending_revokes_the_old_token_and_issues_a_new_one(): void
    {
        [$user, $oldToken] = $this->invitedUser();
        $old = $user->invitations()->sole();

        $result = app(ResendInvitation::class)->handle($user, $this->hrAdmin());
        $newToken = $this->lastInvitationToken();

        $this->assertTrue($result->sent);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull(AccountInvitation::findOpenByToken($oldToken));
        $this->assertTrue($result->invitation->is(AccountInvitation::findOpenByToken($newToken)));
        $this->assertSame(1, AccountInvitation::query()->open()->where('user_id', $user->id)->count());

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->resent);
        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationResent));
    }

    public function test_an_expired_invitation_can_be_resent(): void
    {
        [$user] = $this->invitedUser();

        $this->travel(config('auth.invitations.expire') + 1)->minutes();

        $this->assertTrue(app(ResendInvitation::class)->handle($user, $this->hrAdmin())->sent);
        $this->assertNotNull(AccountInvitation::findOpenByToken($this->lastInvitationToken()));
    }

    public function test_a_failed_resend_is_reported_and_the_old_token_stays_revoked(): void
    {
        [$user, $oldToken] = $this->invitedUser();

        $this->breakTransport();

        $result = app(ResendInvitation::class)->handle($user, $this->hrAdmin());

        $this->assertFalse($result->sent);
        $this->assertNull(AccountInvitation::findOpenByToken($oldToken));
        $this->assertNotNull(AccountInvitation::query()->open()->find($result->invitation->id));
    }

    public function test_only_pending_accounts_can_be_resent_to(): void
    {
        $admin = $this->hrAdmin();

        foreach ([$this->enrolledUser(), User::factory()->suspended()->create()] as $user) {
            try {
                app(ResendInvitation::class)->handle($user, $admin);

                $this->fail('The resend should have been refused.');
            } catch (AccountProtectionException) {
                $this->assertSame(0, $user->invitations()->count());
            }
        }

        Mail::assertNothingSent();
    }

    public function test_a_user_without_the_permission_cannot_resend(): void
    {
        [$user] = $this->invitedUser();

        $this->expectException(AuthorizationException::class);

        app(ResendInvitation::class)->handle($user, $this->enrolledUser());
    }

    public function test_resending_is_throttled_per_user(): void
    {
        [$user] = $this->invitedUser();
        $admin = $this->hrAdmin();

        foreach (range(1, 3) as $attempt) {
            app(ResendInvitation::class)->handle($user, $admin);
        }

        try {
            app(ResendInvitation::class)->handle($user, $admin);

            $this->fail('The fourth resend should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Too many attempts', $exception->errors()['invitation'][0]);
        }

        Mail::assertSent(InvitationMail::class, 4);

        $this->travel(601)->seconds();

        $this->assertTrue(app(ResendInvitation::class)->handle($user, $admin)->sent);
    }
}
