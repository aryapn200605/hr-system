<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Livewire\Users\Index;
use App\Livewire\Users\Show;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InvitesUsers;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InvitesUsers, LogsIn, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->admin = $this->hrAdmin();
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

    private function userList(): Testable
    {
        return Livewire::actingAs($this->admin)->test(Index::class);
    }

    private function employee(array $attributes = []): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::Employee)->create($attributes);
    }

    private function suspendFromUi(User $user): Testable
    {
        return $this->userList()->call('openSuspend', $user->id)->call('confirm');
    }

    private function fillCreateForm(Testable $component, array $overrides = []): Testable
    {
        $fields = [
            'name' => 'Rina Wijaya',
            'username' => 'rina.w',
            'email' => 'rina@example.com',
            'role' => RoleName::Employee->value,
            ...$overrides,
        ];

        foreach ($fields as $field => $value) {
            $component->set($field, $value);
        }

        return $component;
    }

    private function createFromUi(array $overrides = []): Testable
    {
        return $this->fillCreateForm($this->userList()->call('openCreate'), $overrides)->call('saveUser');
    }

    /**
     * Open a link in the invitee's browser, not the admin's.
     */
    private function openLinkAsGuest(string $token): TestResponse
    {
        $this->startNextRequest();

        return $this->openLink($token);
    }

    private function auditCount(AuditEvent $event): int
    {
        return AuditLog::where('event', $event->value)->count();
    }

    /*
     * Create
     */

    public function test_creating_a_user_invites_them_and_audits_it(): void
    {
        $this->createFromUi()
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Invitation sent to rina@example.com.', tone: 'success')
            ->assertDispatched('close-modal', name: 'user-form')
            ->assertSet('notice', '')
            ->assertSee('rina.w');

        $user = User::where('username', 'rina.w')->sole();

        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertSame(RoleName::Employee->value, $user->roleName());
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('rina@example.com'));

        $this->assertSame(1, $this->auditCount(AuditEvent::UserCreated));
        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationCreated));
        $this->assertSame($this->admin->id, AuditLog::where('event', AuditEvent::UserCreated->value)->sole()->actor_id);
    }

    public function test_a_failed_invitation_email_is_reported_and_the_account_stays_resendable(): void
    {
        $this->breakTransport();

        $component = $this->createFromUi()
            ->assertHasNoErrors()
            ->assertNotDispatched('toast')
            ->assertSee('could not be sent')
            ->assertSee('Resend invitation');

        $user = User::where('username', 'rina.w')->sole();

        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertNotNull($user->invitations()->sole());
        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationDeliveryFailed));

        $component->call('openResend', $user->id)->assertOk();
    }

    public function test_create_validates_the_input_and_keeps_the_dialog_open(): void
    {
        $this->createFromUi(['name' => '', 'username' => 'a b', 'email' => 'not-an-email'])
            ->assertHasErrors(['name', 'username', 'email'])
            ->assertNotDispatched('close-modal');

        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }

    public function test_create_refuses_a_taken_username_and_email(): void
    {
        $this->createFromUi(['username' => $this->admin->username, 'email' => $this->admin->email])
            ->assertHasErrors(['username', 'email']);
    }

    public function test_create_refuses_a_role_that_does_not_exist(): void
    {
        $this->createFromUi(['role' => 'Superuser'])->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['username' => 'rina.w']);
    }

    /*
     * Edit
     */

    public function test_editing_a_user_saves_and_audits_the_changed_fields_only(): void
    {
        $user = $this->employee();

        $this->userList()
            ->call('openEdit', $user->id)
            ->assertSet('name', $user->name)
            ->assertSet('email', $user->email)
            ->set('name', 'New Name')
            ->set('email', 'NEW@example.com')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Changes saved.', tone: 'success');

        $fresh = $user->fresh();

        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('new@example.com', $fresh->email);

        $audit = AuditLog::where('event', AuditEvent::UserUpdated->value)->sole();

        $this->assertSame(['fields' => ['name', 'email'], 'invitations_revoked' => 0], $audit->metadata);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertStringNotContainsString('new@example.com', json_encode($audit->metadata));
    }

    public function test_saving_without_a_change_audits_nothing(): void
    {
        $user = $this->employee();

        $this->userList()->call('openEdit', $user->id)->call('saveUser')->assertHasNoErrors();

        $this->assertSame(0, $this->auditCount(AuditEvent::UserUpdated));
    }

    public function test_edit_validates_and_ignores_the_users_own_values_for_uniqueness(): void
    {
        $user = $this->employee();

        $this->userList()
            ->call('openEdit', $user->id)
            ->set('username', $this->admin->username)
            ->set('email', 'nope')
            ->call('saveUser')
            ->assertHasErrors(['username', 'email'])
            ->assertNotDispatched('close-modal');

        $this->assertSame($user->username, $user->fresh()->username);
    }

    public function test_changing_the_email_of_a_pending_account_kills_the_sent_link(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->userList()
            ->call('openEdit', $user->id)
            ->set('email', 'corrected@example.com')
            ->call('saveUser')
            ->assertSee('The invitation already sent no longer works')
            ->assertNotDispatched('toast');

        $this->assertSame(1, AuditLog::where('event', AuditEvent::UserUpdated->value)->sole()->metadata['invitations_revoked']);

        $this->openLinkAsGuest($token)->assertStatus(410);
    }

    public function test_changing_the_username_of_a_pending_account_kills_the_sent_link(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->userList()
            ->call('openEdit', $user->id)
            ->set('username', 'corrected')
            ->call('saveUser')
            ->assertSee('The invitation already sent no longer works');

        $this->openLinkAsGuest($token)->assertStatus(410);
    }

    public function test_changing_only_the_name_of_a_pending_account_keeps_the_link(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->userList()->call('openEdit', $user->id)->set('name', 'Rina W.')->call('saveUser');

        $this->assertNull($user->invitations()->sole()->revoked_at);
        $this->openLinkAsGuest($token)->assertRedirect(route('activation.password'));
    }

    public function test_a_resend_after_an_edit_reaches_the_new_address(): void
    {
        [$user] = $this->invitedUser();

        $this->userList()
            ->call('openEdit', $user->id)
            ->set('email', 'corrected@example.com')
            ->call('saveUser')
            ->call('openResend', $user->id)
            ->call('confirm');

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('corrected@example.com'));

        $this->openLinkAsGuest($this->lastInvitationToken())->assertRedirect(route('activation.password'));
    }

    public function test_changing_an_email_ends_reset_links_sent_to_the_old_address(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);

        DB::table('password_reset_tokens')->insert(['email' => 'old@example.com', 'token' => 'hash', 'created_at' => now()]);

        $this->userList()->call('openEdit', $user->id)->set('email', 'new@example.com')->call('saveUser');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.com']);
    }

    public function test_an_admin_may_change_only_their_own_name(): void
    {
        $this->userList()
            ->call('openEdit', $this->admin->id)
            ->assertSet('identityLocked', true)
            ->assertSee('Change your own email in My Profile.')
            ->set('name', 'Renamed Admin')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Admin', $this->admin->fresh()->name);
    }

    public function test_an_admin_cannot_change_their_own_username_or_email(): void
    {
        $original = $this->admin->only(['username', 'email']);

        $this->userList()->call('openEdit', $this->admin->id)->set('username', 'hacked')->call('saveUser')->assertForbidden();
        $this->userList()->call('openEdit', $this->admin->id)->set('email', 'hacked@example.com')->call('saveUser')->assertForbidden();

        $this->assertSame($original, $this->admin->fresh()->only(['username', 'email']));
        $this->assertSame(0, $this->auditCount(AuditEvent::UserUpdated));
    }

    public function test_a_name_change_cannot_carry_an_identity_change_with_it(): void
    {
        $this->userList()
            ->call('openEdit', $this->admin->id)
            ->set('name', 'Renamed Admin')
            ->set('email', 'hacked@example.com')
            ->call('saveUser')
            ->assertForbidden();

        $this->assertNotSame('Renamed Admin', $this->admin->fresh()->name);
    }

    /*
     * Change role
     */

    public function test_changing_a_role_works_and_audits_it(): void
    {
        $user = $this->employee();

        $this->userList()
            ->call('openRole', $user->id)
            ->assertSet('role', RoleName::Employee->value)
            ->set('role', RoleName::Approver->value)
            ->call('saveRole')
            ->assertDispatched('toast', message: $user->name.' is now Approver.', tone: 'success');

        $this->assertSame(RoleName::Approver->value, $user->fresh()->roleName());

        $audit = AuditLog::where('event', AuditEvent::UserRoleChanged->value)->where('subject_id', $user->id)->latest('id')->first();

        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['role' => RoleName::Approver->value], $audit->metadata);
    }

    public function test_choosing_the_same_role_changes_nothing(): void
    {
        $user = $this->employee();
        $before = $this->auditCount(AuditEvent::UserRoleChanged);

        $this->userList()->call('openRole', $user->id)->call('saveRole')->assertDispatched('close-modal', name: 'user-role');

        $this->assertSame($before, $this->auditCount(AuditEvent::UserRoleChanged));
    }

    public function test_an_unknown_role_is_a_validation_error(): void
    {
        $user = $this->employee();

        $this->userList()->call('openRole', $user->id)->set('role', 'Superuser')->call('saveRole')->assertHasErrors(['role']);

        $this->assertSame(RoleName::Employee->value, $user->fresh()->roleName());
    }

    /*
     * Suspend
     */

    public function test_suspending_ends_sessions_and_audits_it(): void
    {
        $user = $this->employee();
        $other = $this->employee();

        foreach ([$user, $user, $other] as $index => $owner) {
            DB::table('sessions')->insert([
                'id' => 'session-'.$index,
                'user_id' => $owner->id,
                'payload' => '',
                'last_activity' => now()->getTimestamp(),
            ]);
        }

        $this->suspendFromUi($user)
            ->assertDispatched('toast', message: $user->name.' was suspended and signed out.', tone: 'success');

        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());

        $audit = AuditLog::where('event', AuditEvent::UserSuspended->value)->sole();

        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['sessions_ended' => 2, 'invitations_revoked' => 0, 'leave_requests_rejected' => 0, 'overtime_requests_rejected' => 0], $audit->metadata);
    }

    public function test_suspending_removes_password_reset_links(): void
    {
        $user = $this->employee();

        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hash', 'created_at' => now()]);

        $this->suspendFromUi($user);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_suspending_twice_reports_it_and_audits_once(): void
    {
        $user = $this->employee();

        $stale = $this->userList()->call('openSuspend', $user->id);

        $this->suspendFromUi($user);

        $stale->call('confirm')->assertOk()->assertSee('already suspended');

        $this->assertSame(1, $this->auditCount(AuditEvent::UserSuspended));
    }

    public function test_the_model_refuses_a_self_suspension_on_its_own(): void
    {
        $this->hrAdmin();

        $this->expectException(AccountProtectionException::class);

        $this->admin->suspend($this->admin);
    }

    public function test_the_model_refuses_a_suspension_without_the_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->employee()->suspend($this->employee());
    }

    public function test_the_model_refuses_a_reactivation_without_the_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        User::factory()->suspended()->create()->reactivate($this->employee());
    }

    public function test_a_stale_instance_cannot_undo_a_reactivation(): void
    {
        $user = $this->employee();
        $stale = User::find($user->id);

        $user->suspend($this->admin);
        $stale->status = UserStatus::Suspended;
        $stale->syncOriginal();
        $user->reactivate($this->admin);

        $stale->suspend($this->admin);

        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
    }

    public function test_a_failure_while_suspending_rolls_everything_back(): void
    {
        $user = $this->employee();

        DB::table('sessions')->insert(['id' => 'kept', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        AuditLog::creating(function (AuditLog $audit) {
            if ($audit->event === AuditEvent::UserSuspended->value) {
                throw new \RuntimeException('Disk full.');
            }
        });

        try {
            $user->suspend($this->admin);
            $this->fail('The exception should reach the caller.');
        } catch (\RuntimeException) {
            $this->assertSame(UserStatus::Active, $user->fresh()->status);
            $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        }
    }

    public function test_a_pending_account_suspended_during_activation_cannot_finish(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->openLinkAsGuest($token);
        $this->submitActivationPassword();
        $secret = $this->activationSecret();

        $stale = User::find($user->id);
        $invitation = $user->invitations()->sole();

        $this->suspendFromUi($user);
        $this->startNextRequest();

        $this->submitActivationCode($this->totp($secret))->assertStatus(410);

        $this->assertNull($stale->completeActivation($invitation, 'hash', $secret, 1));

        $fresh = $user->fresh();

        $this->assertSame(UserStatus::Suspended, $fresh->status);
        $this->assertNull($fresh->password);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertFalse($fresh->hasCompletedTwoFactorEnrollment());
        $this->assertSame(0, $this->auditCount(AuditEvent::InvitationConsumed));
    }

    /*
     * Reactivate
     */

    public function test_reactivating_restores_access_without_touching_role_or_2fa(): void
    {
        $user = $this->employee();
        $secret = $user->two_factor_secret;

        $this->suspendFromUi($user);

        $this->userList()
            ->call('openReactivate', $user->id)
            ->call('confirm')
            ->assertDispatched('toast', message: $user->name.' can sign in again.', tone: 'success');

        $fresh = $user->fresh();

        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertSame(RoleName::Employee->value, $fresh->roleName());
        $this->assertSame($secret, $fresh->two_factor_secret);

        $audit = AuditLog::where('event', AuditEvent::UserReactivated->value)->sole();

        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame($user->id, $audit->subject_id);
    }

    public function test_reactivating_does_not_revive_an_old_session(): void
    {
        $user = User::factory()->suspended()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();

        DB::table('sessions')->insert(['id' => 'in-flight', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->userList()->call('openReactivate', $user->id)->call('confirm');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_a_pending_account_is_not_activated_by_reactivate(): void
    {
        $user = User::factory()->pending()->withRole(RoleName::Employee)->create();

        $this->userList()
            ->call('openReactivate', $user->id)
            ->call('confirm')
            ->assertOk()
            ->assertSee('Only suspended accounts can be reactivated.');

        $this->assertSame(UserStatus::Pending, $user->fresh()->status);
        $this->assertSame(0, $this->auditCount(AuditEvent::UserReactivated));
    }

    public function test_an_account_suspended_before_activation_cannot_be_reactivated(): void
    {
        [$user] = $this->invitedUser();

        $this->suspendFromUi($user);

        $this->assertStringNotContainsString('openReactivate('.$user->id.')', $this->userList()->html());

        $this->userList()
            ->call('openReactivate', $user->id)
            ->call('confirm')
            ->assertOk()
            ->assertSee('never finished activation');

        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
        $this->assertSame(0, $this->auditCount(AuditEvent::UserReactivated));
    }

    public function test_an_active_account_cannot_be_reactivated(): void
    {
        $user = $this->employee();

        $this->userList()->call('openReactivate', $user->id)->call('confirm')->assertSee('Only suspended accounts can be reactivated.');

        $this->assertSame(0, $this->auditCount(AuditEvent::UserReactivated));
    }

    public function test_the_row_menu_offers_reactivate_instead_of_suspend(): void
    {
        $suspended = User::factory()->suspended()->twoFactorEnrolled()->withRole(RoleName::Employee)->create();
        $active = $this->employee();

        $html = $this->userList()->html();

        $this->assertStringContainsString('openReactivate('.$suspended->id.')', $html);
        $this->assertStringNotContainsString('openSuspend('.$suspended->id.')', $html);
        $this->assertStringContainsString('openSuspend('.$active->id.')', $html);
        $this->assertStringNotContainsString('openReactivate('.$active->id.')', $html);
    }

    /*
     * Resend
     */

    public function test_resending_replaces_the_link_and_audits_it(): void
    {
        [$user, $oldToken] = $this->invitedUser();

        $this->userList()
            ->call('openResend', $user->id)
            ->call('confirm')
            ->assertDispatched('toast', message: 'A new invitation was sent to '.$user->email.'.', tone: 'success');

        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationResent));
        $this->assertNotSame($oldToken, $this->lastInvitationToken());
        $this->openLinkAsGuest($oldToken)->assertStatus(410);
    }

    public function test_a_failed_resend_says_no_link_works_and_can_be_retried(): void
    {
        [$user] = $this->invitedUser();

        $this->breakTransport();

        $component = $this->userList()
            ->call('openResend', $user->id)
            ->call('confirm')
            ->assertNotDispatched('toast')
            ->assertSee('could not be sent')
            ->assertSee('no working link');

        $this->assertSame(1, $this->auditCount(AuditEvent::InvitationDeliveryFailed));

        $component->call('openResend', $user->id)->assertOk()->assertSet('confirming', 'resend');
    }

    public function test_resending_is_throttled_with_a_message(): void
    {
        [$user] = $this->invitedUser();

        foreach (range(1, 3) as $attempt) {
            $this->userList()->call('openResend', $user->id)->call('confirm');
        }

        $this->userList()->call('openResend', $user->id)->call('confirm')->assertOk()->assertSee('Too many attempts');

        $this->assertSame(3, $this->auditCount(AuditEvent::InvitationResent));
    }

    public function test_only_pending_accounts_are_offered_a_resend(): void
    {
        $active = $this->employee();

        $this->assertStringNotContainsString('openResend('.$active->id.')', $this->userList()->html());

        $this->userList()->call('openResend', $active->id)->assertForbidden();
    }

    public function test_a_resend_refuses_an_account_activated_after_the_dialog_opened(): void
    {
        [$user] = $this->invitedUser();

        $component = $this->userList()->call('openResend', $user->id);

        User::whereKey($user->id)->update(['status' => UserStatus::Active]);

        $component->call('confirm')->assertForbidden();

        $this->assertSame(0, $this->auditCount(AuditEvent::InvitationResent));
    }

    /*
     * Details
     */

    public function test_the_detail_page_shows_account_and_2fa_status(): void
    {
        $user = $this->employee(['last_login_at' => now()]);

        $this->actingAs($this->admin)->get(route('users.show', $user->id))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->username)
            ->assertSee($user->email)
            ->assertSee(RoleName::Employee->value)
            ->assertSee('Active')
            ->assertSee('Enabled');
    }

    public function test_the_detail_page_of_a_pending_account_is_honest_about_2fa(): void
    {
        $user = User::factory()->pending()->withRole(RoleName::Employee)->create();

        $this->actingAs($this->admin)->get(route('users.show', $user->id))
            ->assertOk()
            ->assertSee('Not enabled')
            ->assertSee('Never');
    }

    public function test_no_secret_reaches_the_list_or_the_details(): void
    {
        $user = $this->employee();
        $codes = RecoveryCode::regenerateFor($user);
        $secret = $user->two_factor_secret;
        $encrypted = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $pages = [
            $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent(),
            $this->get(route('users.show', $user->id))->assertOk()->getContent(),
            $this->userList()->call('openEdit', $user->id)->html(),
            json_encode(Livewire::actingAs($this->admin)->test(Show::class, ['user' => (string) $user->id])->snapshot),
        ];

        foreach ($pages as $page) {
            $this->assertStringNotContainsString($secret, $page);
            $this->assertStringNotContainsString($encrypted, $page);
            $this->assertStringNotContainsString($user->password, $page);
            $this->assertStringNotContainsString($codes[0], $page);
            $this->assertFalse(Str::contains($page, 'two_factor_secret'));
        }
    }

    public function test_the_list_query_does_not_load_secrets(): void
    {
        DB::enableQueryLog();

        $this->userList();

        $query = collect(DB::getQueryLog())->pluck('query')
            ->first(fn (string $query) => str_contains($query, 'from `users` order by `id` desc'));

        $this->assertNotNull($query);
        $this->assertStringStartsWith('select `id`, `name`', $query);
        $this->assertStringNotContainsString('two_factor_secret', $query);
        $this->assertStringNotContainsString('`password`', $query);
    }
}
