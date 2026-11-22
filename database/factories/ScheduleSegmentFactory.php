<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WeatherType;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleSegment>
 */
class ScheduleSegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_version_id' => ScheduleVersion::factory(),
            'weekday' => 1,
            'starts_at' => '00:00',
            'practice_minutes' => 120,
            'qualifying_minutes' => null,
            'race_duration_type' => null,
            'race_minutes' => null,
            'race_laps' => null,
            'start_hour' => 14,
            'weather' => WeatherType::Clear,
        ];
    }
}
