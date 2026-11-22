<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScheduleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleVersion>
 */
class ScheduleVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => now(),
            'created_by' => null,
        ];
    }
}
