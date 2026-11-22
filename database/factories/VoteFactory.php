<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vote>
 */
class VoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'week_id' => fn (array $attributes): int => Proposal::query()->where('id', $attributes['proposal_id'])->firstOrFail()->week_id,
            'user_id' => null,
            'steam_id' => fn (): string => '7656119'.fake()->unique()->numerify('##########'),
        ];
    }
}
