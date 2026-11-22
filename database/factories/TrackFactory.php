<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TrackMode;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $track = fake()->unique()->word();
        $layout = fake()->randomElement(['gp', 'short', 'indy']);
        $eventName = 'race';
        $lengthM = fake()->numberBetween(2500, 7000);

        return [
            'mode' => TrackMode::RaceWeekend,
            'token' => "{$track}|{$layout}|{$eventName}|{$lengthM}",
            'display' => ucfirst($track),
            'track' => $track,
            'layout' => $layout,
            'event_name' => $eventName,
            'country' => fake()->randomElement(['GB', 'IT', 'DE', 'US', 'JP', 'FR']),
            'length_m' => $lengthM,
            'max_pit_slot' => 24,
            'paired_track_id' => null,
            'active' => true,
            'proposable' => true,
            'synced_at' => now(),
        ];
    }

    public function practice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => TrackMode::Practice,
            'event_name' => 'practice',
            'token' => "{$attributes['track']}|{$attributes['layout']}|practice|{$attributes['length_m']}",
        ]);
    }

    public function raceWeekend(): static
    {
        return $this->state(fn (): array => ['mode' => TrackMode::RaceWeekend]);
    }

    public function pairedWith(Track $practice): static
    {
        return $this->state(fn (): array => ['paired_track_id' => $practice->id]);
    }

    public function withPracticePair(): static
    {
        return $this->state(fn (array $attributes): array => [
            'paired_track_id' => static::new()->practice()->createOne([
                'track' => $attributes['track'],
                'layout' => $attributes['layout'],
                'length_m' => $attributes['length_m'],
                'display' => $attributes['display'],
            ])->id,
        ]);
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
