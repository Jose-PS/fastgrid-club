<?php

declare(strict_types=1);

use App\Actions\Servers\IngestServerLogs;
use App\Actions\Stats\LogResults;
use App\Enums\ResultSource;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\LogProcessor;
use App\Integration\Server\Log\SessionType;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\RaceResult;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Integration/ScriptedEventDriver.php';

final class StatsDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new ScriptedEventDriver;
    }
}

function statsFixture(string $name, int $shiftDays = 0, int $shiftMinutes = 0): array
{
    $lines = file(__DIR__.'/../../Fixtures/logs/'.$name, FILE_IGNORE_NEW_LINES);

    if ($shiftDays === 0 && $shiftMinutes === 0) {
        return $lines;
    }

    return array_map(fn (string $line): string => preg_replace_callback(
        '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\]/',
        fn (array $match): string => '['.CarbonImmutable::parse($match[1], 'UTC')->addDays($shiftDays)->addMinutes($shiftMinutes)->format('Y-m-d H:i:s.v').']',
        $line,
    ), $lines);
}

function statsIngest(Server $server, array $lines): void
{
    ScriptedEventDriver::$lines = $lines;

    app(IngestServerLogs::class)->handle($server);
}

function statsWeekFor(Server $server, array $lines): Week
{
    $processed = (new LogProcessor)->process($lines);
    $first = $processed->laps[0]->recordedAt;

    foreach (collect($processed->laps)->pluck('carRuntimeName')->unique() as $runtime) {
        Car::factory()->create(['runtime_name' => $runtime]);
    }

    return Week::query()->create([
        'server_id' => $server->id,
        'status' => WeekStatus::Active,
        'starts_at' => $first->subDays(5),
        'ends_at' => $first->addDays(2),
        'voting_closes_at' => $first->subDays(6),
        'track_id' => Track::factory()->withPracticePair()->create()->id,
    ]);
}

function raceSession(array $attributes = []): ServerSession
{
    return ServerSession::factory()->create(array_merge([
        'type' => SessionType::Race,
        'started_at' => CarbonImmutable::parse('2026-11-07 17:30:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-11-07 17:50:00', 'UTC'),
    ], $attributes));
}

function driverLaps(ServerSession $session, Driver $driver, array $times, ?Car $car = null, array $invalid = []): void
{
    $at = $session->started_at->copy();

    foreach ($times as $index => $ms) {
        $at = $at->addMilliseconds($ms);

        Lap::factory()->create([
            'server_id' => $session->server_id,
            'server_session_id' => $session->id,
            'driver_id' => $driver->id,
            'car_id' => $car?->id,
            'lap_ms' => $ms,
            'lap_number' => $index + 1,
            'is_valid' => ! in_array($index, $invalid, true),
            'recorded_at' => $at,
        ]);
    }
}

beforeEach(function () {
    app()->instance(ServerDriverManager::class, new StatsDriverManager);
    ScriptedEventDriver::reset();
});

test('standings are ordered by laps then total time with gaps lapped and unclassified drivers', function () {
    $session = raceSession();
    [$winner, $second, $lapped, $retired] = Driver::factory()->count(4)->create();

    driverLaps($session, $winner, array_fill(0, 10, 90000));
    driverLaps($session, $second, array_fill(0, 10, 91000));
    driverLaps($session, $lapped, array_fill(0, 9, 90500));
    driverLaps($session, $retired, array_fill(0, 3, 90000));

    app(LogResults::class)->rebuild($session);

    $results = RaceResult::query()->where('server_session_id', $session->id)->orderBy('position')->get();

    expect($results->pluck('driver_id')->all())->toBe([$winner->id, $second->id, $lapped->id, $retired->id])
        ->and($results->pluck('laps_completed')->all())->toBe([10, 10, 9, 3])
        ->and($results[0]->total_ms)->toBe(900000)
        ->and($results[0]->gap_ms)->toBe(0)
        ->and($results[1]->gap_ms)->toBe(10000)
        ->and($results[2]->lapped)->toBeTrue()
        ->and($results[2]->gap_ms)->toBeNull()
        ->and($results[2]->classified)->toBeTrue()
        ->and($results[2]->total_ms)->toBe(814500)
        ->and($results[3]->classified)->toBeFalse()
        ->and($results[3]->total_ms)->toBeNull()
        ->and($results[3]->gap_ms)->toBeNull()
        ->and($results[3]->lapped)->toBeTrue()
        ->and($results->pluck('source')->unique()->all())->toBe([ResultSource::Log]);
});

test('the best lap ignores invalid laps and the dominant car is stored', function () {
    $session = raceSession();
    $driver = Driver::factory()->create();
    $car = Car::factory()->create();

    driverLaps($session, $driver, [90000, 70000, 91000], $car, invalid: [1]);

    app(LogResults::class)->rebuild($session);

    $result = RaceResult::query()->sole();

    expect($result->best_lap_ms)->toBe(90000)
        ->and($result->car_id)->toBe($car->id);
});

test('rebuilding replaces the previous results of the session', function () {
    $session = raceSession();
    $driver = Driver::factory()->create();
    driverLaps($session, $driver, [90000, 90000]);

    app(LogResults::class)->rebuild($session);
    driverLaps($session, Driver::factory()->create(), [89000, 89000, 89000]);
    app(LogResults::class)->rebuild($session);

    expect(RaceResult::query()->count())->toBe(2)
        ->and(RaceResult::query()->where('position', 1)->value('laps_completed'))->toBe(3);
});

test('official results prevail over the reconstruction', function () {
    $session = raceSession();
    RaceResult::factory()->create(['server_session_id' => $session->id, 'source' => ResultSource::Server]);
    driverLaps($session, Driver::factory()->create(), [90000, 90000]);

    app(LogResults::class)->rebuild($session);

    expect(RaceResult::query()->count())->toBe(1)
        ->and(RaceResult::query()->sole()->source)->toBe(ResultSource::Server);
});

test('only race sessions are reconstructed', function () {
    $session = ServerSession::factory()->create(['type' => SessionType::Qualifying]);
    driverLaps($session, Driver::factory()->create(), [90000]);

    app(LogResults::class)->rebuild($session);

    expect(RaceResult::query()->count())->toBe(0);
});

test('the grid comes from the qualifying of the same window and is empty without it', function () {
    $server = Server::factory()->create();
    $race = raceSession(['server_id' => $server->id]);
    [$a, $b, $c] = Driver::factory()->count(3)->create();

    driverLaps($race, $a, array_fill(0, 5, 90000));
    driverLaps($race, $b, array_fill(0, 5, 91000));
    driverLaps($race, $c, array_fill(0, 5, 92000));

    app(LogResults::class)->rebuild($race);

    expect(RaceResult::query()->whereNotNull('grid_position')->count())->toBe(0);

    $qualifying = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->subMinutes(30),
        'ended_at' => $race->started_at,
    ]);
    driverLaps($qualifying, $c, [88000]);
    driverLaps($qualifying, $a, [89000, 88500]);

    $stale = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->subHours(5),
        'ended_at' => $race->started_at->subHours(4),
    ]);
    driverLaps($stale, $b, [80000]);

    app(LogResults::class)->rebuild($race);

    $grid = RaceResult::query()->pluck('grid_position', 'driver_id');

    expect($grid[$c->id])->toBe(1)
        ->and($grid[$a->id])->toBe(2)
        ->and($grid[$b->id])->toBeNull();
});

test('with two qualifyings in the window the latest one before the race gives the grid', function () {
    $server = Server::factory()->create();
    $race = raceSession(['server_id' => $server->id]);
    [$a, $b] = Driver::factory()->count(2)->create();

    driverLaps($race, $a, array_fill(0, 5, 90000));
    driverLaps($race, $b, array_fill(0, 5, 91000));

    $earlier = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->subMinutes(30),
        'ended_at' => $race->started_at->subMinutes(20),
    ]);
    driverLaps($earlier, $a, [88000]);
    driverLaps($earlier, $b, [89000]);

    $latest = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->subMinutes(10),
        'ended_at' => $race->started_at,
    ]);
    driverLaps($latest, $a, [89500]);
    driverLaps($latest, $b, [87000]);

    $after = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->addMinutes(5),
        'ended_at' => $race->started_at->addMinutes(15),
    ]);
    driverLaps($after, $a, [80000]);

    app(LogResults::class)->rebuild($race);

    $grid = RaceResult::query()->pluck('grid_position', 'driver_id');

    expect($grid[$b->id])->toBe(1)
        ->and($grid[$a->id])->toBe(2);
});

test('the grid is empty when there is no schedule version in effect', function () {
    ScheduleVersion::query()->delete();
    $server = Server::factory()->create();
    $race = raceSession(['server_id' => $server->id]);
    $driver = Driver::factory()->create();
    driverLaps($race, $driver, array_fill(0, 5, 90000));

    $qualifying = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Qualifying,
        'started_at' => $race->started_at->subMinutes(30),
        'ended_at' => $race->started_at,
    ]);
    driverLaps($qualifying, $driver, [88000]);

    app(LogResults::class)->rebuild($race);

    expect(RaceResult::query()->count())->toBe(1)
        ->and(RaceResult::query()->whereNotNull('grid_position')->count())->toBe(0);
});

test('ingesting the race weekend fixture stores best laps and the provisional results of the race', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = statsFixture('race-weekend.log');
    $week = statsWeekFor($server, $lines);

    statsIngest($server, $lines);

    $race = ServerSession::query()->where('type', SessionType::Race)->firstOrFail();
    $results = RaceResult::query()->where('server_session_id', $race->id)->orderBy('position')->get();
    $totals = Lap::query()->where('server_session_id', $race->id)->groupBy('driver_id')->selectRaw('driver_id, sum(lap_ms) as total')->orderBy('total')->pluck('total', 'driver_id');
    $circuitId = $week->track->paired_track_id ?? $week->track_id;

    expect($results)->toHaveCount(2)
        ->and($results->pluck('laps_completed')->all())->toBe([9, 9])
        ->and($results->pluck('driver_id')->all())->toBe($totals->keys()->all())
        ->and($results->pluck('lapped')->unique()->all())->toBe([false])
        ->and($results->pluck('best_lap_ms')->min())->toBe((int) Lap::query()->where('server_session_id', $race->id)->min('lap_ms'))
        ->and($results->pluck('source')->unique()->all())->toBe([ResultSource::Log])
        ->and($results->pluck('car_id')->filter()->count())->toBe(2)
        ->and(BestLap::query()->where('track_id', $circuitId)->count())->toBeGreaterThan(0)
        ->and(BestLap::query()->where('track_id', $circuitId)->min('lap_ms'))->toBe((int) Lap::query()->whereIn('track_id', array_unique([$week->track_id, $circuitId]))->where('is_valid', true)->min('lap_ms'));

    statsIngest($server, $lines);

    expect(RaceResult::query()->count())->toBe(2);
});

test('sessions of a race window get the index of the race inside the window', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = statsFixture('race-weekend.log', shiftDays: 5, shiftMinutes: 10);
    $week = statsWeekFor($server, $lines);
    $week->update(['starts_at' => CarbonImmutable::parse('2026-11-02 00:00:00', 'Europe/Madrid'), 'ends_at' => CarbonImmutable::parse('2026-11-09 00:00:00', 'Europe/Madrid')]);

    statsIngest($server, $lines);

    expect(ServerSession::query()->where('type', SessionType::Qualifying)->value('cycle_index'))->toBe(0)
        ->and(ServerSession::query()->where('type', SessionType::Race)->value('cycle_index'))->toBe(0)
        ->and(RaceResult::query()->first()->cycle_index)->toBe(0);
});

function raceDayLines(array $shifts): array
{
    $lines = [];

    foreach ($shifts as [$days, $minutes]) {
        $lines = array_merge($lines, statsFixture('race-weekend.log', shiftDays: $days, shiftMinutes: $minutes));
    }

    return $lines;
}

test('races are numbered from zero inside the window and each qualifying takes the index of the race that follows', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = raceDayLines([[5, 10], [5, 70], [5, 130]]);
    statsWeekFor($server, $lines);

    statsIngest($server, $lines);

    $races = ServerSession::query()->where('type', SessionType::Race)->orderBy('started_at')->pluck('cycle_index')->all();
    $qualifyings = ServerSession::query()->where('type', SessionType::Qualifying)->orderBy('started_at')->pluck('cycle_index')->all();

    expect($races)->toBe([0, 1, 2])
        ->and($qualifyings)->toBe([0, 1, 2]);
});

test('ingesting the same lines again does not renumber the races', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = raceDayLines([[5, 10], [5, 70]]);
    statsWeekFor($server, $lines);

    statsIngest($server, $lines);
    statsIngest($server, $lines);

    expect(ServerSession::query()->where('type', SessionType::Race)->orderBy('started_at')->pluck('cycle_index')->all())->toBe([0, 1])
        ->and(ServerSession::query()->count())->toBe(4);
});

test('a later ingestion continues the numbering of the window', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = raceDayLines([[5, 10], [5, 70]]);
    statsWeekFor($server, $lines);

    statsIngest($server, array_slice($lines, 0, count($lines) / 2));
    statsIngest($server, $lines);

    expect(ServerSession::query()->where('type', SessionType::Race)->orderBy('started_at')->pluck('cycle_index')->all())->toBe([0, 1]);
});

test('each race window starts its own numbering at zero', function () {
    $server = Server::factory()->create(['is_active' => true]);
    $lines = raceDayLines([[5, 10], [5, 70], [12, 10]]);
    statsWeekFor($server, $lines);

    statsIngest($server, $lines);

    expect(ServerSession::query()->where('type', SessionType::Race)->orderBy('started_at')->pluck('cycle_index')->all())->toBe([0, 1, 0]);
});

test('sessions get no cycle index when there is no schedule version in effect', function () {
    ScheduleVersion::query()->delete();
    $server = Server::factory()->create(['is_active' => true]);
    $lines = statsFixture('race-weekend.log');
    statsWeekFor($server, $lines);

    statsIngest($server, $lines);

    expect(ServerSession::query()->whereNotNull('cycle_index')->count())->toBe(0)
        ->and(ServerSession::query()->count())->toBe(2);
});
