<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'steam_id' => '7656119'.fake()->unique()->numerify('##########'),
            'last_name' => fake()->userName(),
            'user_id' => null,
            'hidden' => false,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ];
    }
}
