<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CatalogSync;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSync>
 */
class CatalogSyncFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'summary' => [
                'cars' => ['created' => 0, 'updated' => 0, 'deactivated' => 0],
                'tracks' => ['created' => 0, 'updated' => 0, 'deactivated' => 0],
                'unpaired_race_tracks' => [],
            ],
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ];
    }
}
