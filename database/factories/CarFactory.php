<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_name' => fake()->unique()->slug(3),
            'display_name' => fake()->unique()->words(3, true),
            'type' => 'GT',
            'era' => 'modern',
            'engine' => 'V8',
            'pi' => fake()->randomFloat(1, 8, 40),
            'brand' => fake()->company(),
            'classes' => ['GT3'],
            'is_mod' => false,
            'active' => true,
            'proposable' => true,
            'synced_at' => now(),
        ];
    }

    public function mod(): static
    {
        return $this->state(fn (): array => ['is_mod' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function notProposable(): static
    {
        return $this->state(fn (): array => ['proposable' => false]);
    }
}
