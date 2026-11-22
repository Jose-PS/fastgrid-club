<?php

declare(strict_types=1);

use App\Actions\Stats\LogResults;
use App\Actions\Stats\RecordBestLaps;
use App\Integration\Server\Log\SessionType;
use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\RaceResult;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use App\Support\HomeStats;
use App\Support\HomeStatsCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function cachedStats(): HomeStats
{
    return app(HomeStats::class);
}

function cacheLap(Track $track, Car $car, Driver $driver, int $ms): Lap
{
    return Lap::factory()->create([
        'track_id' => $track->id,
        'car_id' => $car->id,
        'driver_id' => $driver->id,
        'lap_ms' => $ms,
        'is_valid' => true,
        'recorded_at' => now(),
    ]);
}

function cacheRaceDay(): ServerSession
{
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->create();
    $start = CarbonImmutable::parse('2026-11-07 17:30:00', 'UTC');

    return ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Race,
        'started_at' => $start,
        'ended_at' => $start->addMinutes(20),
        'week_id' => $week->id,
        'cycle_index' => 0,
    ]);
}

function queryCount(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();

    return count(DB::getQueryLog());
}

test('a second read of the best laps is served from the cache without queries', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'lap_ms' => 90000]);

    $first = cachedStats()->bestLaps($track->id, $car->id, null);
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'lap_ms' => 80000]);

    $queries = queryCount(function () use (&$second, $track, $car): void {
        $second = cachedStats()->bestLaps($track->id, $car->id, null);
    });

    expect($queries)->toBe(0)
        ->and($second)->toBe($first)
        ->and($second['rows'])->toHaveCount(1);
});

test('best laps are cached per track, car and page', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'lap_ms' => 90000]);

    cachedStats()->bestLaps(null, null, null);

    $filtered = queryCount(fn () => cachedStats()->bestLaps($track->id, $car->id, null));
    $otherPage = queryCount(fn () => cachedStats()->bestLaps(null, null, null, 2));

    expect($filtered)->toBeGreaterThan(0)
        ->and($otherPage)->toBeGreaterThan(0)
        ->and(HomeStatsCache::bestLapsKey(null, null, 1))->not->toBe(HomeStatsCache::bestLapsKey($track->id, $car->id, 1));
});

test('best laps expire after thirty seconds and results after sixty', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'lap_ms' => 90000]);
    $heat = cacheRaceDay();

    cachedStats()->bestLaps(null, null, null);
    cachedStats()->lastRaceDay($heat->id, null);

    $this->travel(29)->seconds();
    expect(queryCount(fn () => cachedStats()->bestLaps(null, null, null)))->toBe(0);

    $this->travel(2)->seconds();
    expect(queryCount(fn () => cachedStats()->bestLaps(null, null, null)))->toBeGreaterThan(0)
        ->and(queryCount(fn () => cachedStats()->lastRaceDay($heat->id, null)))->toBe(0);

    $this->travel(30)->seconds();
    expect(queryCount(fn () => cachedStats()->lastRaceDay($heat->id, null)))->toBeGreaterThan(0);
});

test('a lap that improves a best lap invalidates the cached board immediately', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    $driver = Driver::factory()->create();
    $record = app(RecordBestLaps::class);

    $record->handle([cacheLap($track, $car, $driver, 91000)]);
    expect(cachedStats()->bestLaps(null, null, null)['rows'][0]['lapMs'])->toBe(91000);

    $record->handle([cacheLap($track, $car, $driver, 90000)]);

    expect(cachedStats()->bestLaps(null, null, null)['rows'][0]['lapMs'])->toBe(90000);
});

test('a lap that does not improve a best lap keeps the cache', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    $driver = Driver::factory()->create();
    $record = app(RecordBestLaps::class);

    $record->handle([cacheLap($track, $car, $driver, 90000)]);
    cachedStats()->bestLaps(null, null, null);
    $key = HomeStatsCache::bestLapsKey(null, null, 1);

    $record->handle([cacheLap($track, $car, $driver, 95000)]);

    expect(HomeStatsCache::bestLapsKey(null, null, 1))->toBe($key)
        ->and(queryCount(fn () => cachedStats()->bestLaps(null, null, null)))->toBe(0);
});

test('rebuilding the results of a heat invalidates the cached results', function () {
    $heat = cacheRaceDay();
    $car = Car::factory()->create();
    $driver = Driver::factory()->create(['last_name' => 'Late Finisher']);

    expect(cachedStats()->lastRaceDay(null, null)['rows'])->toBe([]);

    Lap::factory()->create([
        'server_id' => $heat->server_id,
        'server_session_id' => $heat->id,
        'driver_id' => $driver->id,
        'car_id' => $car->id,
        'lap_ms' => 90000,
        'recorded_at' => $heat->started_at->addMinutes(2),
    ]);
    app(LogResults::class)->rebuild($heat);

    $rows = cachedStats()->lastRaceDay(null, null)['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['driver'])->toBe('Late Finisher');
});

test('each viewer sees their own mark on the same cached page and nothing personal is cached', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $heat = cacheRaceDay();

    foreach ([$mine, $theirs] as $index => $user) {
        $driver = Driver::factory()->create(['user_id' => $user->id, 'last_name' => "Driver {$index}"]);
        BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 90000 + $index * 100]);
        RaceResult::factory()->create(['server_session_id' => $heat->id, 'driver_id' => $driver->id, 'position' => $index + 1]);
    }

    $asMine = cachedStats()->bestLaps(null, null, $mine);
    $asTheirs = cachedStats()->bestLaps(null, null, $theirs);
    $asGuest = cachedStats()->bestLaps(null, null, null);
    $resultsMine = cachedStats()->lastRaceDay(null, $mine);
    $resultsTheirs = cachedStats()->lastRaceDay(null, $theirs);
    $cachedBoard = Cache::get(HomeStatsCache::bestLapsKey(null, null, 1));
    $cachedResults = Cache::get(HomeStatsCache::resultsKey(null, 1));

    expect(array_column($asMine['rows'], 'isYou'))->toBe([true, false])
        ->and(array_column($asTheirs['rows'], 'isYou'))->toBe([false, true])
        ->and(array_column($asGuest['rows'], 'isYou'))->toBe([false, false])
        ->and(array_column($resultsMine['rows'], 'isYou'))->toBe([true, false])
        ->and(array_column($resultsTheirs['rows'], 'isYou'))->toBe([false, true])
        ->and(array_column($cachedBoard['rows'], 'isYou'))->toBe([false, false])
        ->and(array_column($cachedResults['rows'], 'isYou'))->toBe([false, false])
        ->and($asMine['rows'][0])->not->toHaveKey('userId')
        ->and($resultsMine['rows'][0])->not->toHaveKey('userId')
        ->and(json_encode([$cachedBoard, $cachedResults]))->not->toContain('steam');
});
