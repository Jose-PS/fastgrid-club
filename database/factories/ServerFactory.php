<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'driver' => 'fake',
            'ingame_prefix' => 'FastGrid',
            'max_players' => 24,
        ];
    }
}
