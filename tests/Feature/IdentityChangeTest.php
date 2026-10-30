<?php

namespace Tests\Feature;

use App\Actions\UpdateOwnIdentity;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class IdentityChangeTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->logIn($this->user);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function update(array $overrides = []): TestResponse
    {
        return $this->from(route('profile.identity'))->post(route('profile.identity.update'), array_merge([
            'username' => $this->user->username,
            'email' => $this->user->email,
            'email_confirmation' => $this->user->email,
        ], $overrides));
    }

    private function newEmail(string $email = 'New.Address@Example.com'): TestResponse
    {
        return $this->update(['email' => $email, 'email_confirmation' => $email]);
    }

    public function test_the_form_shows_the_current_values(): void
    {
        $this->confirmIdentity($this->user);

        $this->get(route('profile.identity'))
            ->assertOk()
            ->assertSee($this->user->username)
            ->assertSee($this->user->email)
            ->assertSee('No email is sent');
    }

    public function test_the_username_and_email_change_in_lowercase(): void
    {
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'New.Name', 'email' => 'New@Example.com', 'email_confirmation' => 'New@Example.com'])
            ->assertRedirect(route('profile.show'));

        $fresh = $this->user->fresh();

        $this->assertSame('new.name', $fresh->username);
        $this->assertSame('new@example.com', $fresh->email);
    }

    public function test_the_audit_entry_names_only_the_fields(): void
    {
        $this->confirmIdentity($this->user);

        $this->newEmail('audit.new@example.com');

        $entry = AuditLog::where('event', AuditEvent::UserUpdated->value)->sole();

        $this->assertSame(['fields' => ['email']], $entry->metadata);
        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertSame($this->user->id, $entry->subject_id);
        $this->assertStringNotContainsString('audit.new@example.com', $entry->toJson());
    }

    public function test_an_unchanged_form_changes_nothing_and_keeps_the_proof(): void
    {
        $this->confirmIdentity($this->user);

        $this->update()->assertRedirect(route('profile.show'));

        $this->assertSame(0, AuditLog::where('event', AuditEvent::UserUpdated->value)->count());
        $this->get(route('profile.identity'))->assertOk();
    }

    public function test_the_email_must_be_typed_twice(): void
    {
        $this->confirmIdentity($this->user);

        $this->update(['email' => 'new@example.com', 'email_confirmation' => 'nwe@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame($this->user->email, $this->user->fresh()->email);
    }

    public function test_the_username_and_email_follow_the_shared_rules(): void
    {
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'ab'])->assertSessionHasErrors('username');
        $this->update(['username' => 'has space'])->assertSessionHasErrors('username');
        $this->update(['username' => 'has@sign'])->assertSessionHasErrors('username');
        $this->update(['username' => ''])->assertSessionHasErrors('username');
        $this->newEmail('not-an-email')->assertSessionHasErrors('email');
        $this->newEmail('')->assertSessionHasErrors('email');
    }

    public function test_a_username_or_email_of_another_account_is_refused(): void
    {
        $other = $this->enrolledUser();
        $this->confirmIdentity($this->user);

        $this->update(['username' => strtoupper($other->username)])->assertSessionHasErrors('username');
        $this->newEmail(strtoupper($other->email))->assertSessionHasErrors('email');

        $fresh = $this->user->fresh();

        $this->assertSame($this->user->username, $fresh->username);
        $this->assertSame($this->user->email, $fresh->email);
    }

    public function test_an_account_can_keep_its_own_username_and_email(): void
    {
        $this->confirmIdentity($this->user);

        $this->update(['username' => strtoupper($this->user->username)])->assertSessionDoesntHaveErrors();
    }

    public function test_a_collision_at_the_unique_index_becomes_a_validation_error(): void
    {
        $other = $this->enrolledUser();

        // Takes the address after the unique rule passed, as a parallel request would.
        User::saving(function (User $user) use ($other) {
            if ($user->is($this->user)) {
                DB::table('users')->where('id', $other->id)->update(['email' => 'contested@example.com']);
            }
        });

        try {
            (new UpdateOwnIdentity)->handle($this->user, [
                'username' => $this->user->username,
                'email' => 'contested@example.com',
                'email_confirmation' => 'contested@example.com',
            ]);

            $this->fail('The collision was not reported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_a_username_collision_at_the_unique_index_is_reported_on_the_username(): void
    {
        $other = $this->enrolledUser();

        User::saving(function (User $user) use ($other) {
            if ($user->is($this->user)) {
                DB::table('users')->where('id', $other->id)->update(['username' => 'contested']);
            }
        });

        try {
            (new UpdateOwnIdentity)->handle($this->user, [
                'username' => 'contested',
                'email' => $this->user->email,
                'email_confirmation' => $this->user->email,
            ]);

            $this->fail('The collision was not reported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('username', $exception->errors());
        }
    }

    public function test_the_user_row_is_locked_before_it_is_read(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        (new UpdateOwnIdentity)->handle($this->user, [
            'username' => 'locked.change',
            'email' => $this->user->email,
            'email_confirmation' => $this->user->email,
        ]);

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'from `users`') && str_contains($sql, 'for update')));
    }

    public function test_reset_links_for_the_old_and_new_address_are_deleted(): void
    {
        $bystander = $this->enrolledUser();
        Password::createToken($this->user);
        Password::createToken($bystander);

        // A link left behind for an address nobody holds now.
        DB::table('password_reset_tokens')->insert(['email' => 'vacated@example.com', 'token' => 'x', 'created_at' => now()]);

        $this->confirmIdentity($this->user);
        $oldEmail = $this->user->email;

        $this->newEmail('vacated@example.com')->assertRedirect(route('profile.show'));

        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, 'vacated@example.com'])->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $bystander->email)->count());
    }

    public function test_changing_only_the_username_keeps_a_reset_link(): void
    {
        Password::createToken($this->user);
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'only.name']);

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $this->user->email)->count());
    }

    public function test_after_the_change_a_reset_works_only_for_the_new_address(): void
    {
        $oldEmail = $this->user->email;
        $this->confirmIdentity($this->user);
        $this->newEmail('moved@example.com');

        $this->assertSame(Password::INVALID_USER, Password::sendResetLink(['email' => $oldEmail]));
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => 'moved@example.com']));
    }

    public function test_an_old_link_cannot_be_redeemed_after_the_change(): void
    {
        $oldEmail = $this->user->email;
        $token = Password::createToken($this->user);

        $this->confirmIdentity($this->user);
        $this->newEmail('moved@example.com');
        $this->post(route('logout'));
        $this->startNextRequest();

        $this->get(route('password.reset.start', $token));

        foreach ([$oldEmail, 'moved@example.com'] as $email) {
            $this->from(route('password.reset'))->post(route('password.update'), [
                'email' => $email,
                'password' => 'Brand-New-Horse-77',
                'password_confirmation' => 'Brand-New-Horse-77',
            ])->assertSessionHasErrors('email');
        }

        $this->submitPassword($this->user)->assertRedirect(route('two-factor.challenge'));
    }

    public function test_the_new_identifiers_log_in_and_the_old_ones_do_not(): void
    {
        $oldUsername = $this->user->username;
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'moved.user']);

        $this->post(route('logout'));
        $this->startNextRequest();

        $this->post(route('login.store'), ['identifier' => $oldUsername, 'password' => 'password'])->assertSessionHasErrors();
        $this->post(route('login.store'), ['identifier' => 'moved.user', 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
    }

    public function test_no_email_is_sent_for_a_change(): void
    {
        Mail::fake();
        $this->confirmIdentity($this->user);

        $this->newEmail('quiet@example.com')->assertRedirect(route('profile.show'));

        Mail::assertNothingSent();
    }

    public function test_extra_fields_are_ignored(): void
    {
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'plain.change', 'name' => 'Changed Name', 'status' => 'SUSPENDED', 'id' => '999']);

        $fresh = $this->user->fresh();

        $this->assertSame($this->user->name, $fresh->name);
        $this->assertSame($this->user->status, $fresh->status);
        $this->assertSame($this->user->id, $fresh->id);
    }

    public function test_it_changes_only_the_logged_in_user(): void
    {
        $other = $this->enrolledUser();
        $this->confirmIdentity($this->user);

        $this->update(['username' => 'plain.change', 'user_id' => (string) $other->id]);

        $this->assertSame($other->username, $other->fresh()->username);
        $this->assertSame('plain.change', $this->user->fresh()->username);
    }
}
