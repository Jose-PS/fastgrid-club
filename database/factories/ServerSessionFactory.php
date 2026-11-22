<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integration\Server\Log\SessionType;
use App\Models\Server;
use App\Models\ServerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerSession>
 */
class ServerSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => SessionType::Practice,
            'started_at' => now()->subMinutes(30),
            'ended_at' => null,
            'track_id' => null,
            'week_id' => null,
            'cycle_index' => null,
            'source_line_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
