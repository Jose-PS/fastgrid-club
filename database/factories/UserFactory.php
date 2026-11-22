<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => null,
            'remember_token' => Str::random(10),
            'locale' => null,
            'is_admin' => false,
            'show_in_rankings' => true,
        ];
    }

    public function withVerifiedEmail(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => now(),
        ]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn (): array => [
            'email' => null,
            'email_verified_at' => null,
        ]);
    }

    public function withSteam(?string $steamId = null): static
    {
        $identity = UserIdentity::factory()->steam();

        return $this->has($steamId === null ? $identity : $identity->state(['provider_id' => $steamId]), 'identities');
    }

    public function withGoogle(): static
    {
        return $this->has(UserIdentity::factory()->google(), 'identities');
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'is_admin' => true,
        ]);
    }
}
