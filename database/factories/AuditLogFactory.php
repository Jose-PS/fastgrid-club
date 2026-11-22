<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => 'donations.updated',
            'before' => ['goal_cents' => 1000],
            'after' => ['goal_cents' => 2000],
            'ip' => fake()->ipv4(),
        ];
    }
}
