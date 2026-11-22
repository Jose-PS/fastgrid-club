<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Support\HomeStatsCache;
use Illuminate\Support\Facades\DB;

class RebuildBestLaps
{
    public function handle(): int
    {
        $changed = DB::transaction(function (): int {
            $removed = DB::affectingStatement(<<<'SQL'
                DELETE FROM best_laps
                USING tracks
                WHERE tracks.id = best_laps.track_id AND tracks.paired_track_id IS NOT NULL
                SQL);

            return $removed + DB::affectingStatement(<<<'SQL'
                INSERT INTO best_laps (track_id, car_id, driver_id, lap_id, lap_ms, achieved_at)
                SELECT DISTINCT ON (circuit_id, car_id, driver_id) circuit_id, car_id, driver_id, id, lap_ms, recorded_at
                FROM (
                    SELECT laps.id, laps.car_id, laps.driver_id, laps.lap_ms, laps.recorded_at, COALESCE(tracks.paired_track_id, tracks.id) AS circuit_id
                    FROM laps
                    JOIN tracks ON tracks.id = laps.track_id
                    WHERE laps.car_id IS NOT NULL AND laps.is_valid IS DISTINCT FROM false
                ) AS candidates
                ORDER BY circuit_id, car_id, driver_id, lap_ms, recorded_at
                ON CONFLICT (track_id, car_id, driver_id) DO UPDATE
                SET lap_id = EXCLUDED.lap_id, lap_ms = EXCLUDED.lap_ms, achieved_at = EXCLUDED.achieved_at
                WHERE EXCLUDED.lap_ms < best_laps.lap_ms
                SQL);
        });

        if ($changed > 0) {
            HomeStatsCache::forgetBestLaps();
        }

        return $changed;
    }
}
