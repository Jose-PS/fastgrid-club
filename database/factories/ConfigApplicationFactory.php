<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConfigApplicationStatus;
use App\Models\ConfigApplication;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigApplication>
 */
class ConfigApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'week_id' => null,
            'block_key' => null,
            'block' => null,
            'attempt' => 1,
            'status' => ConfigApplicationStatus::Pending,
            'error' => null,
            'snapshot' => [],
            'started_at' => now()->subSecond(),
            'finished_at' => null,
        ];
    }

    public function ok(): static
    {
        return $this->state(fn (): array => ['status' => ConfigApplicationStatus::Ok, 'finished_at' => now()]);
    }

    public function failed(string $error = 'failed'): static
    {
        return $this->state(fn (): array => ['status' => ConfigApplicationStatus::Failed, 'error' => $error, 'finished_at' => now()]);
    }
}
