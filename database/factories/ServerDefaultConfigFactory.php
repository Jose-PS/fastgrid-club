<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerDefaultConfig>
 */
class ServerDefaultConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'track_id' => Track::factory(),
            'custom_title' => null,
        ];
    }
}
