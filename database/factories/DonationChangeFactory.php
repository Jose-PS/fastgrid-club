<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DonationChange;
use App\Models\DonationPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationChange>
 */
class DonationChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donation_period_id' => DonationPeriod::factory(),
            'field' => 'goal',
            'old_cents' => 0,
            'new_cents' => 1000,
        ];
    }
}
