<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class HomeStatsCache
{
    public const BEST_LAPS_TTL = 30;

    public const RESULTS_TTL = 60;

    private const BEST_LAPS_VERSION = 'stats.best_laps.version';

    private const RESULTS_VERSION = 'stats.results.version';

    public static function bestLapsKey(?int $trackId, ?int $carId, int $page): string
    {
        $version = (int) Cache::get(self::BEST_LAPS_VERSION, 0);

        return 'stats.best_laps.v'.$version.'.t'.($trackId ?? 'all').'.c'.($carId ?? 'all').'.p'.$page;
    }

    public static function resultsKey(?int $heatId, int $page): string
    {
        $version = (int) Cache::get(self::RESULTS_VERSION, 0);

        return 'stats.results.v'.$version.'.h'.($heatId ?? 'latest').'.p'.$page;
    }

    public static function forgetBestLaps(): void
    {
        Cache::increment(self::BEST_LAPS_VERSION);
    }

    public static function forgetResults(): void
    {
        Cache::increment(self::RESULTS_VERSION);
    }
}
