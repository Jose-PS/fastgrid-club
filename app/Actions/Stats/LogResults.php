<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Enums\ResultSource;
use App\Integration\Server\Log\SessionType;
use App\Models\Lap;
use App\Models\RaceResult;
use App\Models\ServerSession;
use App\Support\HomeStatsCache;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class LogResults implements ResultsSource
{
    public const CLASSIFIED_PERCENT = 90;

    public function rebuild(ServerSession $session): void
    {
        if ($session->type !== SessionType::Race) {
            return;
        }

        if (RaceResult::query()->where('server_session_id', $session->id)->where('source', ResultSource::Server)->exists()) {
            return;
        }

        $laps = Lap::query()
            ->where('server_session_id', $session->id)
            ->orderBy('recorded_at')
            ->get(['id', 'driver_id', 'car_id', 'lap_ms', 'is_valid', 'recorded_at']);

        $standings = $this->standings($laps);
        $grid = $this->grid($session);
        $leader = $standings[0] ?? null;
        $now = now();
        $rows = [];

        foreach ($standings as $index => $entry) {
            $lapped = $leader !== null && $entry['laps'] < $leader['laps'];
            $classified = $leader !== null && $entry['laps'] * 100 >= $leader['laps'] * self::CLASSIFIED_PERCENT;

            $rows[] = [
                'server_session_id' => $session->id,
                'week_id' => $session->week_id,
                'cycle_index' => $session->cycle_index,
                'driver_id' => $entry['driver_id'],
                'car_id' => $entry['car_id'],
                'position' => $index + 1,
                'laps_completed' => $entry['laps'],
                'total_ms' => $classified ? $entry['total'] : null,
                'gap_ms' => $classified && ! $lapped ? $entry['total'] - $leader['total'] : null,
                'lapped' => $lapped,
                'best_lap_ms' => $entry['best'],
                'grid_position' => $grid[$entry['driver_id']] ?? null,
                'classified' => $classified,
                'source' => ResultSource::Log->value,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($session, $rows): void {
            RaceResult::query()->where('server_session_id', $session->id)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                RaceResult::query()->insert($chunk);
            }
        });

        HomeStatsCache::forgetResults();
    }

    /**
     * @param  Collection<int, Lap>  $laps
     * @return list<array{driver_id: int, car_id: int|null, laps: int, total: int, best: int|null}>
     */
    private function standings(Collection $laps): array
    {
        $entries = [];

        foreach ($laps->groupBy('driver_id') as $driverId => $driverLaps) {
            $valid = $driverLaps->filter(static fn (Lap $lap): bool => $lap->is_valid !== false);
            $cars = $driverLaps->pluck('car_id')->filter()->countBy();

            $entries[] = [
                'driver_id' => (int) $driverId,
                'car_id' => $cars->isEmpty() ? null : (int) $cars->sortDesc()->keys()->first(),
                'laps' => $driverLaps->count(),
                'total' => (int) $driverLaps->sum('lap_ms'),
                'best' => $valid->isEmpty() ? null : (int) $valid->min('lap_ms'),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$b['laps'], $a['total'], $a['driver_id']] <=> [$a['laps'], $b['total'], $b['driver_id']]);

        return $entries;
    }

    /**
     * @return array<int, int>
     */
    private function grid(ServerSession $session): array
    {
        try {
            $window = app(WeekCalendar::class)->scheduleWindowAt(CarbonImmutable::instance($session->started_at));
        } catch (LogicException) {
            return [];
        }

        $qualifying = ServerSession::query()
            ->where('server_id', $session->server_id)
            ->where('type', SessionType::Qualifying)
            ->where('started_at', '<', $session->started_at)
            ->where('started_at', '>=', $window->startsAt)
            ->orderByDesc('started_at')
            ->first();

        if ($qualifying === null) {
            return [];
        }

        $times = Lap::query()
            ->where('server_session_id', $qualifying->id)
            ->where(fn ($query) => $query->whereNull('is_valid')->orWhere('is_valid', true))
            ->groupBy('driver_id')
            ->selectRaw('driver_id, min(lap_ms) as best')
            ->orderBy('best')
            ->orderBy('driver_id')
            ->pluck('best', 'driver_id');

        $grid = [];
        $position = 1;

        foreach ($times as $driverId => $best) {
            $grid[(int) $driverId] = $position++;
        }

        return $grid;
    }
}
