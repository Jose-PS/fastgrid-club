<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\Proposal;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'week_id' => Week::factory(),
            'user_id' => User::factory()->withSteam(),
            'track_id' => Track::factory()->withPracticePair(),
            'signature' => fn (array $attributes): string => Proposal::signatureFor((int) $attributes['track_id'], [fake()->unique()->numberBetween(1, 1_000_000)]),
        ];
    }

    /**
     * @param  array<int, Car>  $cars
     */
    public function withCars(array $cars): static
    {
        return $this->state([
            'signature' => fn (array $attributes): string => Proposal::signatureFor((int) $attributes['track_id'], array_map(fn (Car $car): int => $car->id, $cars)),
        ])->afterCreating(fn (Proposal $proposal) => $proposal->cars()->attach(array_map(fn (Car $car): int => $car->id, $cars)));
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => [
            'hidden_at' => now(),
            'hidden_reason' => 'spam',
        ]);
    }
}
