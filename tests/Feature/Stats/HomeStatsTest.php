<?php

declare(strict_types=1);

use App\Enums\ResultSource;
use App\Integration\Server\Log\SessionType;
use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\RaceResult;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use App\Support\HomeStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function stats(): HomeStats
{
    return app(HomeStats::class);
}

function seedBoard(Track $track, Car $car, int $count, int $base = 90000): void
{
    for ($i = 0; $i < $count; $i++) {
        BestLap::factory()->create([
            'track_id' => $track->id,
            'car_id' => $car->id,
            'driver_id' => Driver::factory()->create(['last_name' => 'Pilot '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->id,
            'lap_ms' => $base + $i * 100,
        ]);
    }
}

test('best laps default to every track and car ordered by time, paginated by 20 with absolute positions', function () {
    $track = Track::factory()->create();
    $other = Track::factory()->create();
    $car = Car::factory()->create();
    seedBoard($track, $car, 30, 90000);
    seedBoard($other, $car, 15, 90050);

    $first = stats()->bestLaps(null, null, null);
    $last = stats()->bestLaps(null, null, null, 3);

    expect($first['rows'])->toHaveCount(20)
        ->and($first['rows'][0])->toMatchArray(['position' => 1, 'lapMs' => 90000, 'gapMs' => null, 'track' => $track->display, 'car' => $car->display_name])
        ->and($first['rows'][1]['lapMs'])->toBe(90050)
        ->and($first['rows'][1]['track'])->toBe($other->display)
        ->and($first['pagination'])->toMatchArray(['currentPage' => 1, 'lastPage' => 3, 'total' => 45, 'perPage' => 20, 'pageName' => 'lb_page'])
        ->and($first['selected'])->toBe(['trackId' => null, 'carId' => null])
        ->and($last['rows'])->toHaveCount(5)
        ->and($last['rows'][0]['position'])->toBe(41);
});

test('filtering by track and car paginates the filtered board and only then gives the gap to the leader', function () {
    $track = Track::factory()->create();
    $other = Track::factory()->create();
    $car = Car::factory()->create();
    $otherCar = Car::factory()->create();
    seedBoard($track, $car, 45);
    seedBoard($other, $car, 3, 80000);
    seedBoard($track, $otherCar, 3, 80000);

    $second = stats()->bestLaps($track->id, $car->id, null, 2);
    $trackOnly = stats()->bestLaps($track->id, null, null);
    $carOnly = stats()->bestLaps(null, $car->id, null);

    expect($second['rows'])->toHaveCount(20)
        ->and($second['rows'][0])->toMatchArray(['position' => 21, 'driver' => 'Pilot 20', 'gapMs' => 2000])
        ->and($second['pagination']['total'])->toBe(45)
        ->and($second['selected'])->toBe(['trackId' => $track->id, 'carId' => $car->id])
        ->and(stats()->bestLaps($track->id, $car->id, null, 3)['rows'])->toHaveCount(5)
        ->and($trackOnly['rows'][0]['gapMs'])->toBeNull()
        ->and($trackOnly['pagination']['total'])->toBe(48)
        ->and($carOnly['rows'][0]['gapMs'])->toBeNull()
        ->and($carOnly['pagination']['total'])->toBe(48);
});

test('ties keep the driver who set the time first', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => Driver::factory()->create(['last_name' => 'Late'])->id, 'lap_ms' => 90000, 'achieved_at' => '2026-11-04 10:00:00']);
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => Driver::factory()->create(['last_name' => 'Early'])->id, 'lap_ms' => 90000, 'achieved_at' => '2026-11-03 10:00:00']);

    expect(collect(stats()->bestLaps(null, null, null)['rows'])->pluck('driver')->all())->toBe(['Early', 'Late']);
});

test('the viewer row is flagged and hidden drivers show the translated label with their time', function () {
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    $viewer = User::factory()->create();
    $private = User::factory()->create(['show_in_rankings' => false]);

    $viewerDriver = Driver::factory()->create(['user_id' => $viewer->id, 'last_name' => 'Me']);
    $privateDriver = Driver::factory()->create(['user_id' => $private->id, 'last_name' => 'Secret']);
    $hiddenDriver = Driver::factory()->create(['hidden' => true, 'last_name' => 'Banned']);

    foreach ([$viewerDriver, $privateDriver, $hiddenDriver] as $index => $driver) {
        BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 90000 + $index * 100]);
    }

    app()->setLocale('es');
    $rows = stats()->bestLaps(null, null, $viewer)['rows'];

    expect(collect($rows)->pluck('isYou')->all())->toBe([true, false, false])
        ->and(collect($rows)->pluck('driver')->all())->toBe(['Me', 'Piloto oculto', 'Piloto oculto'])
        ->and(collect($rows)->pluck('hidden')->all())->toBe([false, true, true])
        ->and($rows[1]['lapMs'])->toBe(90100)
        ->and(collect(stats()->bestLaps(null, null, null)['rows'])->pluck('isYou')->unique()->all())->toBe([false]);
});

test('selectors offer every track and car with laps, narrow the cars by track and treat unknown ids as all', function () {
    $zandvoort = Track::factory()->create(['display' => 'Zandvoort']);
    $imola = Track::factory()->create(['display' => 'Imola']);
    Track::factory()->create(['display' => 'Empty Ring']);
    $weekCar = Car::factory()->create(['display_name' => 'Week Car']);
    $otherCar = Car::factory()->create(['display_name' => 'Other Car']);
    Car::factory()->create(['display_name' => 'No Laps Car']);

    seedBoard($imola, $otherCar, 1);
    seedBoard($zandvoort, $otherCar, 1);
    seedBoard($zandvoort, $weekCar, 2);

    $all = stats()->bestLaps(null, null, null);
    $narrow = stats()->bestLaps($imola->id, null, null);
    $unknown = stats()->bestLaps(999999, 999999, null);
    $carNotInTrack = stats()->bestLaps($imola->id, $weekCar->id, null);

    expect(collect($all['options']['tracks'])->pluck('name')->all())->toBe(['Imola', 'Zandvoort'])
        ->and(collect($all['options']['cars'])->pluck('name')->all())->toBe(['Other Car', 'Week Car'])
        ->and(collect($narrow['options']['cars'])->pluck('name')->all())->toBe(['Other Car'])
        ->and($narrow['rows'])->toHaveCount(1)
        ->and($unknown['selected'])->toBe(['trackId' => null, 'carId' => null])
        ->and($unknown['rows'])->toHaveCount(4)
        ->and($carNotInTrack['selected'])->toBe(['trackId' => $imola->id, 'carId' => null])
        ->and($carNotInTrack['rows'])->toHaveCount(1);
});

test('best laps are empty without data', function () {
    $result = stats()->bestLaps(null, null, null);

    expect($result['options'])->toBe(['tracks' => [], 'cars' => []])
        ->and($result['rows'])->toBe([])
        ->and($result['selected'])->toBe(['trackId' => null, 'carId' => null])
        ->and($result['pagination']['total'])->toBe(0);
});

function raceDay(Week $week, int $cycle, ?string $endedAt = 'set'): ServerSession
{
    $start = CarbonImmutable::parse('2026-11-07 17:30:00', 'UTC')->addHours($cycle);

    return ServerSession::factory()->create([
        'server_id' => $week->server_id,
        'type' => SessionType::Race,
        'started_at' => $start,
        'ended_at' => $endedAt === null ? null : $start->addMinutes(20),
        'week_id' => $week->id,
        'cycle_index' => $cycle,
    ]);
}

test('the last race day lists the finished heats of the latest race window and skips the one in progress', function () {
    $server = Server::factory()->create();
    $old = Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-10-26', 'Europe/Madrid'))->create();
    $latest = Week::factory()->for($server)->startingAt(CarbonImmutable::parse('2026-11-02', 'Europe/Madrid'))->create();

    $oldHeat = raceDay($old, 0);
    $oldHeat->update(['started_at' => '2026-10-31 17:30:00', 'ended_at' => '2026-10-31 17:50:00']);
    $first = raceDay($latest, 0);
    $second = raceDay($latest, 1);
    raceDay($latest, 2, null);
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => SessionType::Qualifying, 'week_id' => $latest->id, 'cycle_index' => 3, 'ended_at' => now()]);
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => SessionType::Race, 'week_id' => $latest->id, 'cycle_index' => null, 'ended_at' => now()]);

    $result = stats()->lastRaceDay(null, null);

    expect(collect($result['heats'])->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(collect($result['heats'])->pluck('number')->all())->toBe([1, 2])
        ->and($result['heats'][0]['startsAt'])->toBe('2026-11-07T18:30:00+01:00')
        ->and($result['heats'][1]['startsAt'])->toBe('2026-11-07T19:30:00+01:00')
        ->and($result['selectedHeatId'])->toBe($second->id)
        ->and(stats()->lastRaceDay($first->id, null)['selectedHeatId'])->toBe($first->id)
        ->and(stats()->lastRaceDay(999999, null)['selectedHeatId'])->toBe($second->id);
});

test('race results are paginated and carry laps gaps positions gained and the fastest lap', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->create();
    $heat = raceDay($week, 0);
    $viewer = User::factory()->create();
    $car = Car::factory()->create(['display_name' => 'GT Car']);

    for ($i = 1; $i <= 25; $i++) {
        RaceResult::factory()->create([
            'server_session_id' => $heat->id,
            'driver_id' => Driver::factory()->create(['last_name' => "Racer {$i}", 'user_id' => $i === 22 ? $viewer->id : null])->id,
            'car_id' => $car->id,
            'position' => $i,
            'laps_completed' => $i > 20 ? 11 : 12,
            'lapped' => $i > 20,
            'gap_ms' => $i > 20 ? null : ($i - 1) * 1000,
            'total_ms' => 1000000 + $i * 1000,
            'best_lap_ms' => 90000 + $i * 10,
            'grid_position' => $i === 3 ? 6 : $i,
            'source' => ResultSource::Log,
        ]);
    }

    $first = stats()->lastRaceDay($heat->id, $viewer);
    $second = stats()->lastRaceDay($heat->id, $viewer, 2);

    expect($first['rows'])->toHaveCount(20)
        ->and($first['hasGrid'])->toBeTrue()
        ->and($first['fastestLapMs'])->toBe(90010)
        ->and($first['rows'][0])->toMatchArray(['position' => 1, 'driver' => 'Racer 1', 'isFastest' => true, 'lapsBehind' => 0, 'positionsGained' => 0, 'car' => 'GT Car'])
        ->and($first['rows'][2]['positionsGained'])->toBe(3)
        ->and($first['rows'][1]['isFastest'])->toBeFalse()
        ->and($first['pagination'])->toMatchArray(['total' => 25, 'lastPage' => 2, 'pageName' => 'res_page'])
        ->and($second['rows'])->toHaveCount(5)
        ->and($second['rows'][0]['position'])->toBe(21)
        ->and($second['rows'][0])->toMatchArray(['lapped' => true, 'lapsBehind' => 1, 'gapMs' => null])
        ->and($second['rows'][1]['isYou'])->toBeTrue();
});

test('a grid is optional in the last race day', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->create();
    $heat = raceDay($week, 0);
    RaceResult::factory()->create(['server_session_id' => $heat->id, 'source' => ResultSource::Server, 'grid_position' => null]);

    $result = stats()->lastRaceDay(null, null);

    expect($result['hasGrid'])->toBeFalse()
        ->and($result['rows'][0]['positionsGained'])->toBeNull();
});

test('the last race day is empty without finished heats', function () {
    $server = Server::factory()->create();
    raceDay(Week::factory()->for($server)->create(), 0, null);

    $result = stats()->lastRaceDay(null, null);

    expect($result['heats'])->toBe([])
        ->and($result['selectedHeatId'])->toBeNull()
        ->and($result['rows'])->toBe([])
        ->and($result['pagination']['total'])->toBe(0);
});

test('both queries never read laps and never expose a steam id', function () {
    $server = Server::factory()->create();
    $week = Week::factory()->for($server)->create();
    $heat = raceDay($week, 0);
    $driver = Driver::factory()->create(['steam_id' => '76561198123456789']);
    $track = Track::factory()->create();
    $car = Car::factory()->create();
    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id]);
    RaceResult::factory()->create(['server_session_id' => $heat->id, 'driver_id' => $driver->id]);

    DB::enableQueryLog();

    $payload = [stats()->bestLaps(null, null, null), stats()->lastRaceDay(null, null)];

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $sql): bool => preg_match('/(from|join)\s+"laps"/i', $sql) === 1))->toBeEmpty()
        ->and($queries->count())->toBeLessThanOrEqual(10)
        ->and(json_encode($payload))->not->toContain('76561198123456789')
        ->and(json_encode($payload))->not->toContain('steam');
});
