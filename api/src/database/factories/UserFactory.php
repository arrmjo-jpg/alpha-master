<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Users\Enums\AccountType;
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
            'username' => 'u'.Str::lower(Str::random(6)).fake()->unique()->numberBetween(100, 999999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'account_type' => AccountType::User,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['account_type' => AccountType::Admin]);
    }

    public function writer(): static
    {
        return $this->state(fn () => ['account_type' => AccountType::Writer]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['deactivated_at' => now()]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['banned_at' => now(), 'ban_reason' => 'Test ban']);
    }
}
