<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentity>
 */
class UserIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => IdentityProvider::Steam,
            'provider_id' => '7656119'.fake()->unique()->numerify('##########'),
            'display_name' => fake()->userName(),
            'avatar_url' => null,
            'linked_at' => now(),
        ];
    }

    public function steam(): static
    {
        return $this->state(fn (): array => [
            'provider' => IdentityProvider::Steam,
            'provider_id' => '7656119'.fake()->unique()->numerify('##########'),
        ]);
    }

    public function google(): static
    {
        return $this->state(fn (): array => [
            'provider' => IdentityProvider::Google,
            'provider_id' => fake()->unique()->numerify('#####################'),
        ]);
    }
}
