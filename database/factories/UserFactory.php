<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->bothify('user.####??'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the account is waiting for activation.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
            'status' => UserStatus::Pending,
        ]);
    }

    /**
     * Indicate that the account is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }

    /**
     * Indicate that the user has finished 2FA enrollment.
     */
    public function twoFactorEnrolled(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => TwoFactor::generateSecret(),
            'two_factor_enrolled_at' => now(),
        ]);
    }

    /**
     * Give the user a role. The role must already be seeded.
     */
    public function withRole(RoleName $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->changeRole($role));
    }
}
