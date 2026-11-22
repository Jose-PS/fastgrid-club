<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RaceDurationType;
use App\Enums\TrackMode;
use App\Enums\WeatherType;
use App\Integration\Server\Data\ConfigPlan;
use App\Models\Server;
use App\Models\ServerOverride;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServerOverride>
 */
class ServerOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plan = new ConfigPlan(
            TrackMode::Practice,
            'Monza|GP|GP Time Attack|5793',
            ['preset_695b_mech_1'],
            'FastGrid | Override',
            20,
            1800,
            1200,
            RaceDurationType::Time,
            null,
            600,
            false,
            14,
            WeatherType::Clear,
        );

        return [
            'key' => (string) Str::uuid(),
            'server_id' => Server::factory(),
            'week_id' => null,
            'plan' => $plan->toArray(),
            'until' => null,
            'ended_at' => null,
            'created_by' => null,
        ];
    }

    public function until(Carbon $until): static
    {
        return $this->state(fn (): array => ['until' => $until]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => ['ended_at' => now()]);
    }
}
