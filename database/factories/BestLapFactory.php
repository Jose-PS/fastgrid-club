<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BestLap>
 */
class BestLapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'car_id' => Car::factory(),
            'driver_id' => Driver::factory(),
            'lap_id' => null,
            'lap_ms' => fake()->numberBetween(80000, 140000),
            'achieved_at' => now(),
        ];
    }
}
