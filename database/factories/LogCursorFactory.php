<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LogCursor;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogCursor>
 */
class LogCursorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'window_hashes' => [],
            'last_event_id' => null,
            'processor_state' => [],
            'last_read_at' => now(),
            'last_gap_at' => null,
        ];
    }
}
