<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Lap;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lap>
 */
class LapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'server_session_id' => null,
            'driver_id' => Driver::factory(),
            'car_id' => null,
            'track_id' => null,
            'lap_ms' => fake()->numberBetween(80000, 140000),
            'lap_number' => null,
            'is_valid' => true,
            'recorded_at' => now(),
            'source_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
