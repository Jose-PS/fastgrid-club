<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntegrationEventType;
use App\Models\IntegrationEvent;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationEvent>
 */
class IntegrationEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => IntegrationEventType::PollFailed,
            'summary' => 'zino_panel status: timeout',
        ];
    }
}
