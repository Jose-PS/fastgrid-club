<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostType;
use App\Models\SocialPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SocialPostType::WeekAnnouncement,
            'key' => 'week_announcement:'.fake()->unique()->numberBetween(1, 1000000),
            'server_id' => null,
            'week_id' => null,
            'status' => SocialPostStatus::Pending,
            'text' => fake()->sentence(),
            'data' => [],
        ];
    }

    public function status(SocialPostStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
