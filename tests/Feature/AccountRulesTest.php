<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountRulesTest extends TestCase
{
    use RefreshDatabase;

    private function usernamePasses(string $username, ?User $ignore = null): bool
    {
        return Validator::make(
            ['username' => $username],
            ['username' => AccountRules::username($ignore)],
        )->passes();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function usernames(): array
    {
        return [
            'letters and digits' => ['rina123', true],
            'dot, underscore and dash' => ['rina.a_b-c', true],
            'uppercase' => ['Rina', true],
            'at sign' => ['rina@admin', false],
            'space' => ['rina admin', false],
            'plus' => ['rina+admin', false],
            'non-ascii letter' => ['rína', false],
            'trailing newline' => ["rina\n", false],
            'too short' => ['ab', false],
            'too long' => [str_repeat('a', 51), false],
        ];
    }

    #[DataProvider('usernames')]
    public function test_username_format(string $username, bool $valid): void
    {
        $this->assertSame($valid, $this->usernamePasses($username));
    }

    public function test_username_must_be_unique_except_for_the_ignored_user(): void
    {
        $user = User::factory()->create(['username' => 'rina.admin']);

        $this->assertFalse($this->usernamePasses('rina.admin'));
        $this->assertTrue($this->usernamePasses('rina.admin', $user));
    }

    public function test_password_defaults_are_defined_once_and_used_by_the_shared_rule(): void
    {
        $this->assertEquals(Password::defaults(), AccountRules::password()[2]);

        $passes = fn (string $password) => Validator::make(
            ['password' => $password],
            ['password' => Password::defaults()],
        )->passes();

        $this->assertTrue($passes('Correct-Horse-42'));
        $this->assertFalse($passes('Abcdefghij1'));
        $this->assertFalse($passes('correct-horse-42'));
        $this->assertFalse($passes('CORRECT-HORSE-42'));
        $this->assertFalse($passes('Correct-Horse-Battery'));
    }

    public function test_secrets_are_hidden_when_a_user_is_serialized(): void
    {
        $user = User::factory()->create(['two_factor_secret' => 'plain-secret']);

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_the_two_factor_secret_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create(['two_factor_secret' => 'plain-secret']);

        $this->assertNotSame('plain-secret', $user->getRawOriginal('two_factor_secret'));
        $this->assertSame('plain-secret', $user->fresh()->two_factor_secret);
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $user = new User(['status' => 'ACTIVE']);

        $this->assertSame('PENDING', $user->status->value);
    }
}
