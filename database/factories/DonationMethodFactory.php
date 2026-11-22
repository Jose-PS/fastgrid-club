<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DonationPlatform;
use App\Models\DonationMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationMethod>
 */
class DonationMethodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->platformState(DonationPlatform::KoFi);
    }

    public function platform(DonationPlatform $platform): static
    {
        return $this->state(fn (): array => $this->platformState($platform));
    }

    /**
     * @return array<string, mixed>
     */
    private function platformState(DonationPlatform $platform): array
    {
        return [
            'platform' => $platform,
            'url' => "https://{$platform->domain()}/acevo",
            'position' => $platform->position(),
        ];
    }
}
