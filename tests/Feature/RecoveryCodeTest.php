<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LoginResult;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\RecoveryCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

class RecoveryCodeTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    private User $user;

    /**
     * @var list<string>
     */
    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeTime();

        $this->user = $this->enrolledUser();
        $this->codes = RecoveryCode::regenerateFor($this->user);
    }

    /**
     * Every recovery_codes and users column for this user, to prove nothing changed.
     *
     * @return array<string, mixed>
     */
    private function storedState(): array
    {
        return [
            'codes' => DB::table('recovery_codes')->orderBy('id')->get()->toArray(),
            'user' => DB::table('users')->where('id', $this->user->id)->first(),
        ];
    }

    public function test_codes_are_issued_in_the_expected_format_and_are_all_different(): void
    {
        $this->assertCount(10, $this->codes);
        $this->assertCount(10, array_unique($this->codes));

        foreach ($this->codes as $code) {
            $this->assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}\z/', $code);
        }
    }

    public function test_a_stored_hash_never_equals_or_contains_the_code(): void
    {
        $hashes = DB::table('recovery_codes')->where('user_id', $this->user->id)->pluck('code_hash');

        $this->assertCount(10, $hashes);

        foreach ($this->codes as $code) {
            $compact = str_replace('-', '', $code);

            foreach ($hashes as $hash) {
                $this->assertNotSame($code, $hash);
                $this->assertStringNotContainsString($code, strtoupper($hash));
                $this->assertStringNotContainsString($compact, strtoupper($hash));
            }
        }

        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        }
    }

    public function test_the_hash_uses_a_derived_key_and_not_app_key_itself(): void
    {
        $normalized = str_replace('-', '', $this->codes[0]);
        $message = $this->user->id.':'.$normalized;
        $hash = RecoveryCode::hashFor($this->user, $this->codes[0]);

        $this->assertNotSame(hash_hmac('sha256', $message, Crypt::getKey()), $hash);
        $this->assertNotSame(hash_hmac('sha256', $message, (string) config('app.key')), $hash);
        $this->assertSame(
            hash_hmac('sha256', $message, hash_hkdf('sha256', Crypt::getKey(), 32, 'hr-system.recovery-codes.v1')),
            $hash,
        );
    }

    public function test_the_same_code_hashes_differently_for_another_user(): void
    {
        $other = $this->enrolledUser();

        $this->assertNotSame(RecoveryCode::hashFor($this->user, $this->codes[0]), RecoveryCode::hashFor($other, $this->codes[0]));
    }

    public function test_rotating_app_key_voids_every_code(): void
    {
        $this->assertTrue(RecoveryCode::query()->exists());

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->assertFalse(RecoveryCode::consume($this->user, $this->codes[0]));
    }

    public function test_code_hash_is_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('recovery_codes'));

        $index = $indexes->first(fn (array $index) => $index['columns'] === ['user_id', 'code_hash']);

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
    }

    public function test_a_code_finishes_the_login_and_is_audited(): void
    {
        $this->submitPassword($this->user);

        $this->submitRecoveryCode($this->codes[0])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotNull(RecoveryCode::where('code_hash', RecoveryCode::hashFor($this->user, $this->codes[0]))->sole()->used_at);
        $this->assertSame(1, $this->user->recoveryCodes()->whereNotNull('used_at')->count());

        $audit = AuditLog::where('event', AuditEvent::RecoveryCodeUsed)->sole();
        $this->assertSame($this->user->id, $audit->actor_id);
        $this->assertSame($this->user->id, $audit->subject_id);
        $this->assertNull($audit->metadata);
        $this->assertSame(['method' => 'recovery_code'], AuditLog::where('event', AuditEvent::LoginSucceeded)->sole()->metadata);
        $this->assertSame(LoginResult::Success->value, LoginHistory::sole()->result);
    }

    public function test_case_and_separators_do_not_matter(): void
    {
        $this->submitPassword($this->user);

        $this->submitRecoveryCode(' '.strtolower(str_replace('-', ' ', $this->codes[0])).' ')
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_code_cannot_be_used_twice_one_after_the_other(): void
    {
        $this->submitPassword($this->user);
        $this->submitRecoveryCode($this->codes[0])->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->startNextRequest();

        // Time must move on: MySQL reports no change for an update to the same second.
        $this->travel(5)->seconds();

        $this->submitPassword($this->user);
        $state = $this->storedState();

        $this->submitRecoveryCode($this->codes[0])->assertSessionHasErrors(['recovery_code' => 'The code is invalid.']);

        $this->assertGuest();
        $this->assertEquals($state, $this->storedState());
        $this->assertSame(1, AuditLog::where('event', AuditEvent::RecoveryCodeUsed)->count());
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $this->submitPassword($this->user);
        $state = $this->storedState();

        $this->submitRecoveryCode('AAAA-BBBB-CCCC-DDDD')->assertSessionHasErrors(['recovery_code' => 'The code is invalid.']);

        $this->assertGuest();
        $this->assertEquals($state, $this->storedState());
        $this->assertSame(0, AuditLog::where('event', AuditEvent::RecoveryCodeUsed)->count());
        $this->assertSame(LoginResult::FailedRecovery->value, LoginHistory::sole()->result);
        $this->assertSame(10, $this->user->recoveryCodes()->whereNull('used_at')->count());
    }

    public function test_another_users_code_changes_nothing(): void
    {
        $other = $this->enrolledUser();
        $otherCodes = RecoveryCode::regenerateFor($other);

        $this->submitPassword($this->user);
        $state = $this->storedState();

        $this->submitRecoveryCode($otherCodes[0])->assertSessionHasErrors('recovery_code');

        $this->assertEquals($state, $this->storedState());
    }

    public function test_the_code_is_validated(): void
    {
        $this->submitPassword($this->user);

        $this->submitRecoveryCode('')->assertSessionHasErrors('recovery_code');
        $this->submitRecoveryCode(str_repeat('A', 65))->assertSessionHasErrors('recovery_code');

        $this->assertSame(0, LoginHistory::count());
    }

    public function test_a_recovery_login_works_without_touching_the_totp_timestep(): void
    {
        $this->submitPassword($this->user);
        $this->submitRecoveryCode($this->codes[0]);

        $this->assertNull($this->user->fresh()->two_factor_last_timestep);
    }

    public function test_recovery_needs_a_pending_login(): void
    {
        $this->submitRecoveryCode($this->codes[0])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, $this->user->recoveryCodes()->whereNotNull('used_at')->count());
    }

    public function test_regenerating_replaces_the_old_codes(): void
    {
        $new = RecoveryCode::regenerateFor($this->user);

        $this->assertSame(10, $this->user->recoveryCodes()->count());
        $this->assertFalse(RecoveryCode::consume($this->user, $this->codes[0]));
        $this->assertTrue(RecoveryCode::consume($this->user, $new[0]));
    }
}
