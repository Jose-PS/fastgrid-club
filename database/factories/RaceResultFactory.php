<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResultSource;
use App\Models\Driver;
use App\Models\RaceResult;
use App\Models\ServerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceResult>
 */
class RaceResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_session_id' => ServerSession::factory(),
            'week_id' => null,
            'cycle_index' => null,
            'driver_id' => Driver::factory(),
            'car_id' => null,
            'position' => 1,
            'laps_completed' => 10,
            'total_ms' => 900000,
            'gap_ms' => 0,
            'lapped' => false,
            'best_lap_ms' => 88000,
            'grid_position' => null,
            'classified' => true,
            'source' => ResultSource::Log,
            'created_at' => now(),
        ];
    }
}
