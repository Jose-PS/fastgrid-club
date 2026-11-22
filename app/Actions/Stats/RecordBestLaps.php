<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\BestLap;
use App\Models\Lap;
use App\Models\Track;
use App\Support\HomeStatsCache;
use Illuminate\Support\Collection;

class RecordBestLaps
{
    private const CHUNK = 500;

    /**
     * @param  iterable<Lap>  $laps
     */
    public function handle(iterable $laps): int
    {
        $laps = collect($laps);
        $circuits = $this->circuits($laps);
        $candidates = [];

        foreach ($laps as $lap) {
            if (! $this->counts($lap)) {
                continue;
            }

            $circuitId = $circuits[$lap->track_id] ?? null;

            if ($circuitId === null) {
                continue;
            }

            $key = "{$circuitId}:{$lap->car_id}:{$lap->driver_id}";
            $current = $candidates[$key]['lap'] ?? null;

            if ($current === null || $this->beats($lap, $current)) {
                $candidates[$key] = ['circuit_id' => $circuitId, 'lap' => $lap];
            }
        }

        $changed = 0;

        foreach (array_chunk($candidates, self::CHUNK) as $chunk) {
            $changed += $this->store($chunk);
        }

        return $changed;
    }

    /**
     * @param  Collection<int, Lap>  $laps
     * @return array<int, int>
     */
    private function circuits(Collection $laps): array
    {
        return Track::query()
            ->whereIn('id', $laps->pluck('track_id')->filter()->unique()->values())
            ->get(['id', 'paired_track_id'])
            ->mapWithKeys(static fn (Track $track): array => [$track->id => $track->paired_track_id ?? $track->id])
            ->all();
    }

    private function counts(Lap $lap): bool
    {
        return $lap->track_id !== null && $lap->car_id !== null && $lap->is_valid !== false;
    }

    private function beats(Lap $lap, Lap $current): bool
    {
        if ($lap->lap_ms !== $current->lap_ms) {
            return $lap->lap_ms < $current->lap_ms;
        }

        return $lap->recorded_at < $current->recorded_at;
    }

    /**
     * @param  list<array{circuit_id: int, lap: Lap}>  $chunk
     */
    private function store(array $chunk): int
    {
        $existing = BestLap::query()
            ->whereIn('track_id', array_values(array_unique(array_column($chunk, 'circuit_id'))))
            ->whereIn('car_id', array_values(array_unique(array_map(static fn (array $candidate): int => (int) $candidate['lap']->car_id, $chunk))))
            ->whereIn('driver_id', array_values(array_unique(array_map(static fn (array $candidate): int => $candidate['lap']->driver_id, $chunk))))
            ->get()
            ->keyBy(static fn (BestLap $best): string => "{$best->track_id}:{$best->car_id}:{$best->driver_id}");

        $rows = [];

        foreach ($chunk as ['circuit_id' => $circuitId, 'lap' => $lap]) {
            $current = $existing->get("{$circuitId}:{$lap->car_id}:{$lap->driver_id}");

            if ($current !== null && $current->lap_ms <= $lap->lap_ms) {
                continue;
            }

            $rows[] = [
                'track_id' => $circuitId,
                'car_id' => $lap->car_id,
                'driver_id' => $lap->driver_id,
                'lap_id' => $lap->id,
                'lap_ms' => $lap->lap_ms,
                'achieved_at' => $lap->recorded_at->utc()->format('Y-m-d H:i:s.vP'),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        BestLap::query()->upsert($rows, ['track_id', 'car_id', 'driver_id'], ['lap_id', 'lap_ms', 'achieved_at']);

        HomeStatsCache::forgetBestLaps();

        return count($rows);
    }
}
