<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DonationPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationPeriod>
 */
class DonationPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'month' => CarbonImmutable::now(config()->string('mvp.timezone'))->startOfMonth()->toDateString(),
            'goal_cents' => 5000,
            'covered_cents' => 0,
        ];
    }

    public function forMonth(string $month): static
    {
        return $this->state(fn (): array => ['month' => $month]);
    }

    public function amounts(int $goalCents, int $coveredCents): static
    {
        return $this->state(fn (): array => ['goal_cents' => $goalCents, 'covered_cents' => $coveredCents]);
    }
}
