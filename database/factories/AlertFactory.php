<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AlertType::WeekFallbackPendingReview,
            'status' => AlertStatus::Open,
            'summary' => fake()->sentence(),
            'opened_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => AlertStatus::Closed, 'closed_at' => now()]);
    }
}
