<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\AccountProtectionException;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42';

    /**
     * @param  array<string, string>  $overrides
     */
    private function runCommand(array $overrides = []): PendingCommand
    {
        $input = array_merge([
            'name' => 'Rina Admin',
            'username' => 'rina.admin',
            'email' => 'rina@example.com',
            'password' => self::PASSWORD,
            'confirmation' => self::PASSWORD,
        ], $overrides);

        return $this->artisan('app:create-admin')
            ->expectsQuestion('Full name', $input['name'])
            ->expectsQuestion('Username', $input['username'])
            ->expectsQuestion('Email', $input['email'])
            ->expectsQuestion('Password', $input['password'])
            ->expectsQuestion('Confirm password', $input['confirmation']);
    }

    public function test_it_creates_an_active_hr_admin_with_incomplete_2fa(): void
    {
        $this->runCommand()->assertSuccessful();

        $admin = User::where('username', 'rina.admin')->sole();

        $this->assertSame('Rina Admin', $admin->name);
        $this->assertSame('rina@example.com', $admin->email);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue($admin->hasRole(RoleName::HrAdmin));
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertFalse($admin->hasCompletedTwoFactorEnrollment());
        $this->assertNull($admin->two_factor_secret);
    }

    public function test_it_seeds_roles_and_permissions_first(): void
    {
        $this->assertSame(0, Role::count());

        $this->runCommand()->assertSuccessful();

        $this->assertSame(count(RoleName::cases()), Role::count());
    }

    public function test_it_stores_username_and_email_in_lowercase(): void
    {
        $this->runCommand(['username' => 'Rina.Admin', 'email' => 'RINA@Example.com'])->assertSuccessful();

        $admin = User::sole();

        $this->assertSame('rina.admin', $admin->username);
        $this->assertSame('rina@example.com', $admin->email);
    }

    public function test_it_records_an_audit_entry_without_credentials(): void
    {
        $this->runCommand()->assertSuccessful();

        $entry = AuditLog::where('event', AuditEvent::AdminBootstrapped->value)->sole();

        $this->assertTrue($entry->subject->is(User::sole()));
        $this->assertNull($entry->actor_id);
        $this->assertStringNotContainsString(self::PASSWORD, $entry->toJson());
    }

    public function test_it_refuses_when_an_active_hr_admin_exists(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->withRole(RoleName::HrAdmin)->create();

        $this->artisan('app:create-admin')
            ->expectsOutputToContain('An active HR Admin already exists.')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_runs_when_the_only_hr_admin_is_suspended(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->suspended()->withRole(RoleName::HrAdmin)->create();

        $this->runCommand()->assertSuccessful();

        $this->assertSame(1, User::query()->activeHrAdmin()->count());
    }

    public function test_the_model_refuses_a_second_admin_past_the_command_check(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->withRole(RoleName::HrAdmin)->create();

        $this->expectException(AccountProtectionException::class);

        User::createFirstHrAdmin([
            'name' => 'Second Admin',
            'username' => 'second.admin',
            'email' => 'second@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing name' => [['name' => '']],
            'missing username' => [['username' => '']],
            'username too short' => [['username' => 'ab']],
            'username with @' => [['username' => 'rina@admin']],
            'username with space' => [['username' => 'rina admin']],
            'username with slash' => [['username' => 'rina/admin']],
            'missing email' => [['email' => '']],
            'invalid email' => [['email' => 'not-an-email']],
            'missing password' => [['password' => '', 'confirmation' => '']],
            'password too short' => [['password' => 'Short-1', 'confirmation' => 'Short-1']],
            'password without uppercase' => [['password' => 'correct-horse-42', 'confirmation' => 'correct-horse-42']],
            'password without lowercase' => [['password' => 'CORRECT-HORSE-42', 'confirmation' => 'CORRECT-HORSE-42']],
            'password without number' => [['password' => 'Correct-Horse-Battery', 'confirmation' => 'Correct-Horse-Battery']],
            'password mismatch' => [['confirmation' => 'Different-Horse-42']],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_it_rejects_invalid_input(array $overrides): void
    {
        $this->runCommand($overrides)->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_duplicate_username_and_email(): void
    {
        User::factory()->create(['username' => 'taken.name', 'email' => 'taken@example.com']);

        $this->runCommand(['username' => 'TAKEN.name', 'email' => 'taken@example.com'])
            ->expectsOutputToContain('username has already been taken')
            ->expectsOutputToContain('email has already been taken')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_validation_errors_never_echo_the_password(): void
    {
        $this->runCommand(['password' => 'Short-1', 'confirmation' => 'Short-1'])
            ->doesntExpectOutputToContain('Short-1')
            ->assertFailed();
    }
}
