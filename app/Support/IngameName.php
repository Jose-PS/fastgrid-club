<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Server;
use App\Models\Week;

final class IngameName
{
    private const TAGLINE = 'Race Fair, Get Faster';

    public function for(Server $server, ?Week $week): string
    {
        $customTitle = $week === null ? '' : trim((string) $week->custom_title);

        if ($customTitle !== '') {
            return $customTitle;
        }

        $label = $week === null ? null : $this->label($week);
        $track = $week?->track?->display;

        if ($label === null || $track === null || $track === '') {
            return $server->ingame_prefix.' | '.self::TAGLINE;
        }

        return $server->ingame_prefix.' | '.$label.' @ '.$track.' | '.self::TAGLINE;
    }

    private function label(Week $week): ?string
    {
        $cars = $week->cars;

        if ($cars->isEmpty()) {
            return null;
        }

        if ($cars->count() === 1) {
            return $cars->first()->display_name;
        }

        $common = array_values(array_intersect(...$cars->map(fn ($car): array => $car->classes)->all()));

        return $common[0] ?? $cars->first()->classes[0] ?? null;
    }
}
