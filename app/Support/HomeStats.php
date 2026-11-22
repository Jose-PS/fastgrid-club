<?php

declare(strict_types=1);

namespace App\Support;

use App\Integration\Server\Log\SessionType;
use App\Models\BestLap;
use App\Models\RaceResult;
use App\Models\ServerSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class HomeStats
{
    public const PER_PAGE = 20;

    public const LAPS_PAGE_NAME = 'lb_page';

    public const RESULTS_PAGE_NAME = 'res_page';

    public function __construct(private readonly WeekCalendar $calendar) {}

    /**
     * @return array{
     *     options: array{tracks: list<array{id: int, name: string}>, cars: list<array{id: int, name: string}>},
     *     selected: array{trackId: int|null, carId: int|null},
     *     rows: list<array{position: int, driver: string, hidden: bool, isYou: bool, track: string, car: string, lapMs: int, gapMs: int|null, achievedAt: string}>,
     *     pagination: array{currentPage: int, lastPage: int, total: int, perPage: int, from: int|null, to: int|null, pageName: string}
     * }
     */
    public function bestLaps(?int $trackId, ?int $carId, ?User $viewer, int $page = 1): array
    {
        $page = max(1, $page);

        $payload = Cache::remember(
            HomeStatsCache::bestLapsKey($trackId, $carId, $page),
            HomeStatsCache::BEST_LAPS_TTL,
            fn (): array => $this->buildBestLaps($trackId, $carId, $page),
        );

        $payload['rows'] = $this->markViewer($payload['rows'], $viewer);

        return $payload;
    }

    /**
     * @return array{
     *     options: array{tracks: list<array{id: int, name: string}>, cars: list<array{id: int, name: string}>},
     *     selected: array{trackId: int|null, carId: int|null},
     *     rows: list<array{position: int, driver: string, hidden: bool, isYou: bool, userId: int|null, track: string, car: string, lapMs: int, gapMs: int|null, achievedAt: string}>,
     *     pagination: array{currentPage: int, lastPage: int, total: int, perPage: int, from: int|null, to: int|null, pageName: string}
     * }
     */
    private function buildBestLaps(?int $trackId, ?int $carId, int $page): array
    {
        $combos = BestLap::query()
            ->join('tracks', 'tracks.id', '=', 'best_laps.track_id')
            ->join('cars', 'cars.id', '=', 'best_laps.car_id')
            ->groupBy('tracks.id', 'tracks.display', 'cars.id', 'cars.display_name')
            ->get([
                'tracks.id as track_id',
                'tracks.display as track_name',
                'cars.id as car_id',
                'cars.display_name as car_name',
                DB::raw('min(best_laps.lap_ms) as top'),
                DB::raw('count(*) as total'),
            ]);

        $tracks = array_values($combos
            ->unique('track_id')
            ->sortBy(fn ($combo): string => mb_strtolower((string) $combo->getAttribute('track_name')))
            ->map(fn ($combo): array => ['id' => (int) $combo->getAttribute('track_id'), 'name' => (string) $combo->getAttribute('track_name')])
            ->all());

        $selectedTrack = $trackId !== null && in_array($trackId, array_column($tracks, 'id'), true) ? $trackId : null;

        $cars = array_values($combos
            ->when($selectedTrack !== null, fn ($rows) => $rows->filter(fn ($combo): bool => (int) $combo->getAttribute('track_id') === $selectedTrack))
            ->unique('car_id')
            ->sortBy(fn ($combo): string => mb_strtolower((string) $combo->getAttribute('car_name')))
            ->map(fn ($combo): array => ['id' => (int) $combo->getAttribute('car_id'), 'name' => (string) $combo->getAttribute('car_name')])
            ->all());

        $selectedCar = $carId !== null && in_array($carId, array_column($cars, 'id'), true) ? $carId : null;

        $matching = $combos
            ->when($selectedTrack !== null, fn ($rows) => $rows->filter(fn ($combo): bool => (int) $combo->getAttribute('track_id') === $selectedTrack))
            ->when($selectedCar !== null, fn ($rows) => $rows->filter(fn ($combo): bool => (int) $combo->getAttribute('car_id') === $selectedCar));

        $total = (int) $matching->sum(fn ($combo): int => (int) $combo->getAttribute('total'));
        $top = $selectedTrack !== null && $selectedCar !== null ? (int) $matching->min(fn ($combo): int => (int) $combo->getAttribute('top')) : null;

        $items = BestLap::query()
            ->join('drivers', 'drivers.id', '=', 'best_laps.driver_id')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->join('tracks', 'tracks.id', '=', 'best_laps.track_id')
            ->join('cars', 'cars.id', '=', 'best_laps.car_id')
            ->when($selectedTrack !== null, fn ($query) => $query->where('best_laps.track_id', $selectedTrack))
            ->when($selectedCar !== null, fn ($query) => $query->where('best_laps.car_id', $selectedCar))
            ->orderBy('best_laps.lap_ms')
            ->orderBy('best_laps.achieved_at')
            ->orderBy('best_laps.id')
            ->forPage($page, self::PER_PAGE)
            ->get(['best_laps.lap_ms', 'best_laps.achieved_at', 'drivers.last_name', 'drivers.hidden', 'drivers.user_id', 'users.show_in_rankings', 'tracks.display as track_name', 'cars.display_name as car_name']);

        $first = ($page - 1) * self::PER_PAGE + 1;
        $rows = [];

        foreach ($items as $index => $item) {
            $hidden = $this->isHidden($item->getAttribute('hidden'), $item->getAttribute('show_in_rankings'));
            $lapMs = (int) $item->getAttribute('lap_ms');

            $rows[] = [
                'position' => $first + $index,
                'driver' => $this->driverName($hidden, $item->getAttribute('last_name')),
                'hidden' => $hidden,
                'isYou' => false,
                'userId' => $item->getAttribute('user_id') === null ? null : (int) $item->getAttribute('user_id'),
                'track' => (string) $item->getAttribute('track_name'),
                'car' => (string) $item->getAttribute('car_name'),
                'lapMs' => $lapMs,
                'gapMs' => $top === null ? null : $lapMs - $top,
                'achievedAt' => CarbonImmutable::parse($item->getAttribute('achieved_at'))->setTimezone($this->calendar->timezone())->format('c'),
            ];
        }

        return [
            'options' => ['tracks' => $tracks, 'cars' => $cars],
            'selected' => ['trackId' => $selectedTrack, 'carId' => $selectedCar],
            'rows' => $rows,
            'pagination' => $this->pagination($total, $page, count($rows), self::LAPS_PAGE_NAME),
        ];
    }

    /**
     * @return array{
     *     heats: list<array{id: int, index: int, number: int, startsAt: string}>,
     *     selectedHeatId: int|null,
     *     hasGrid: bool,
     *     fastestLapMs: int|null,
     *     rows: list<array{position: int, driver: string, hidden: bool, isYou: bool, car: string|null, lapsCompleted: int, totalMs: int|null, gapMs: int|null, lapped: bool, lapsBehind: int, bestLapMs: int|null, isFastest: bool, positionsGained: int|null, classified: bool}>,
     *     pagination: array{currentPage: int, lastPage: int, total: int, perPage: int, from: int|null, to: int|null, pageName: string}
     * }
     */
    public function lastRaceDay(?int $heatId, ?User $viewer, int $page = 1): array
    {
        $page = max(1, $page);

        $payload = Cache::remember(
            HomeStatsCache::resultsKey($heatId, $page),
            HomeStatsCache::RESULTS_TTL,
            fn (): array => $this->buildLastRaceDay($heatId, $page),
        );

        $payload['rows'] = $this->markViewer($payload['rows'], $viewer);

        return $payload;
    }

    /**
     * @return array{
     *     heats: list<array{id: int, index: int, number: int, startsAt: string}>,
     *     selectedHeatId: int|null,
     *     hasGrid: bool,
     *     fastestLapMs: int|null,
     *     rows: list<array{position: int, driver: string, hidden: bool, isYou: bool, userId: int|null, car: string|null, lapsCompleted: int, totalMs: int|null, gapMs: int|null, lapped: bool, lapsBehind: int, bestLapMs: int|null, isFastest: bool, positionsGained: int|null, classified: bool}>,
     *     pagination: array{currentPage: int, lastPage: int, total: int, perPage: int, from: int|null, to: int|null, pageName: string}
     * }
     */
    private function buildLastRaceDay(?int $heatId, int $page): array
    {
        $latest = ServerSession::query()
            ->where('type', SessionType::Race)
            ->whereNotNull('cycle_index')
            ->whereNotNull('ended_at')
            ->orderByDesc('started_at')
            ->first(['started_at']);

        $sessions = collect();

        if ($latest !== null) {
            try {
                $window = $this->calendar->scheduleWindowAt(CarbonImmutable::instance($latest->started_at));

                $sessions = ServerSession::query()
                    ->where('type', SessionType::Race)
                    ->whereNotNull('cycle_index')
                    ->whereNotNull('ended_at')
                    ->where('started_at', '>=', $window->startsAt)
                    ->where('started_at', '<', $window->endsAt)
                    ->orderBy('started_at')
                    ->orderBy('id')
                    ->get(['id', 'cycle_index', 'started_at']);
            } catch (LogicException) {
                $sessions = collect();
            }
        }

        $heats = [];

        foreach ($sessions as $session) {
            $index = (int) $session->cycle_index;

            $heats[] = [
                'id' => (int) $session->id,
                'index' => $index,
                'number' => $index + 1,
                'startsAt' => $this->calendar->iso(CarbonImmutable::instance($session->started_at)),
            ];
        }

        if ($heats === []) {
            return [
                'heats' => [],
                'selectedHeatId' => null,
                'hasGrid' => false,
                'fastestLapMs' => null,
                'rows' => [],
                'pagination' => $this->pagination(0, 1, 0, self::RESULTS_PAGE_NAME),
            ];
        }

        $ids = array_column($heats, 'id');
        $selected = in_array($heatId, $ids, true) ? $heatId : $ids[array_key_last($ids)];

        $summary = RaceResult::query()
            ->where('server_session_id', $selected)
            ->selectRaw('max(laps_completed) as leader_laps, min(best_lap_ms) as fastest, count(grid_position) as grids, count(*) as total')
            ->first();

        $leaderLaps = (int) ($summary?->getAttribute('leader_laps') ?? 0);
        $fastest = $summary?->getAttribute('fastest');

        $items = RaceResult::query()
            ->join('drivers', 'drivers.id', '=', 'race_results.driver_id')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->leftJoin('cars', 'cars.id', '=', 'race_results.car_id')
            ->where('race_results.server_session_id', $selected)
            ->orderBy('race_results.position')
            ->forPage($page, self::PER_PAGE)
            ->get([
                'race_results.position',
                'race_results.laps_completed',
                'race_results.total_ms',
                'race_results.gap_ms',
                'race_results.lapped',
                'race_results.best_lap_ms',
                'race_results.grid_position',
                'race_results.classified',
                'drivers.last_name',
                'drivers.hidden',
                'drivers.user_id',
                'users.show_in_rankings',
                'cars.display_name as car_name',
            ]);

        $rows = [];

        foreach ($items as $item) {
            $hidden = $this->isHidden($item->getAttribute('hidden'), $item->getAttribute('show_in_rankings'));
            $laps = (int) $item->getAttribute('laps_completed');
            $bestLap = $item->getAttribute('best_lap_ms');
            $grid = $item->getAttribute('grid_position');
            $position = (int) $item->getAttribute('position');

            $rows[] = [
                'position' => $position,
                'driver' => $this->driverName($hidden, $item->getAttribute('last_name')),
                'hidden' => $hidden,
                'isYou' => false,
                'userId' => $item->getAttribute('user_id') === null ? null : (int) $item->getAttribute('user_id'),
                'car' => $item->getAttribute('car_name'),
                'lapsCompleted' => $laps,
                'totalMs' => $item->getAttribute('total_ms') === null ? null : (int) $item->getAttribute('total_ms'),
                'gapMs' => $item->getAttribute('gap_ms') === null ? null : (int) $item->getAttribute('gap_ms'),
                'lapped' => (bool) $item->getAttribute('lapped'),
                'lapsBehind' => max(0, $leaderLaps - $laps),
                'bestLapMs' => $bestLap === null ? null : (int) $bestLap,
                'isFastest' => $bestLap !== null && $fastest !== null && (int) $bestLap === (int) $fastest,
                'positionsGained' => $grid === null ? null : (int) $grid - $position,
                'classified' => (bool) $item->getAttribute('classified'),
            ];
        }

        return [
            'heats' => $heats,
            'selectedHeatId' => $selected,
            'hasGrid' => (int) ($summary?->getAttribute('grids') ?? 0) > 0,
            'fastestLapMs' => $fastest === null ? null : (int) $fastest,
            'rows' => $rows,
            'pagination' => $this->pagination((int) ($summary?->getAttribute('total') ?? 0), $page, count($rows), self::RESULTS_PAGE_NAME),
        ];
    }

    private function isHidden(mixed $driverHidden, mixed $showInRankings): bool
    {
        return (bool) $driverHidden || ($showInRankings !== null && ! $showInRankings);
    }

    private function driverName(bool $hidden, mixed $name): string
    {
        return $hidden ? __('lb.hidden') : (string) ($name ?? '');
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    private function markViewer(array $rows, ?User $viewer): array
    {
        $marked = array_map(static function (array $row) use ($viewer): array {
            $row['isYou'] = $viewer !== null && $row['userId'] !== null && $row['userId'] === $viewer->id;
            unset($row['userId']);

            return $row;
        }, $rows);

        /** @var list<TRow> $marked */
        return $marked;
    }

    /**
     * @return array{currentPage: int, lastPage: int, total: int, perPage: int, from: int|null, to: int|null, pageName: string}
     */
    private function pagination(int $total, int $page, int $count, string $pageName): array
    {
        $from = $count > 0 ? ($page - 1) * self::PER_PAGE + 1 : null;

        return [
            'currentPage' => $page,
            'lastPage' => max((int) ceil($total / self::PER_PAGE), 1),
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'from' => $from,
            'to' => $from === null ? null : $from + $count - 1,
            'pageName' => $pageName,
        ];
    }
}
