<?php

declare(strict_types=1);

namespace App\Actions\Weeks;

use App\Models\Server;
use App\Models\Track;
use App\Models\Week;

class ApplyWeekConfig
{
    /**
     * @param  array<int, int>  $carIds
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Week $week, Server $server, Track $track, array $carIds, array $attributes): void
    {
        $week->update([
            'track_id' => $track->id,
            'max_players' => $server->capacityFor($track),
            ...$attributes,
        ]);

        $week->cars()->sync(array_values(array_unique($carIds)));
    }
}
