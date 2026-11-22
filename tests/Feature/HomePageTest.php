<?php

declare(strict_types=1);

use App\Actions\Servers\PollServer;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Models\Car;
use App\Models\Driver;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use App\Support\ServerSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoStatsSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Integration/ZinoPanelHelpers.php';

function homeTravel(string $moment): CarbonImmutable
{
    $instant = CarbonImmutable::parse($moment, 'Europe/Madrid');
    test()->travelTo($instant);

    return $instant;
}

function configuredServer(int $maxPlayers = 24, int $pitSlots = 24, ?CarbonImmutable $at = null): Server
{
    $server = Server::factory()->create(['is_active' => true, 'max_players' => $maxPlayers]);
    $track = Track::factory()->create(['display' => 'Imola GP', 'max_pit_slot' => $pitSlots, 'length_m' => 4909]);
    $week = Week::factory()->for($server)->startingAt($at ?? CarbonImmutable::now())->status(WeekStatus::Active)->create([
        'track_id' => $track->id,
        'max_players' => min($maxPlayers, $pitSlots),
    ]);
    $week->cars()->attach([
        Car::factory()->create(['display_name' => 'Ferrari 296 GT3'])->id,
        Car::factory()->create(['display_name' => 'Porsche 911 GT3 R'])->id,
    ]);

    return $server;
}

function homeProps(): array
{
    return test()->get(route('home'))->assertOk()->inertiaProps();
}

test('the home page renders its component with the headline and every section title', function () {
    $response = $this->withHeader('Accept-Language', 'es')->get(route('home'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('welcome'));

    expect($response->inertiaProps()['pageTranslations'])->toMatchArray([
        'hero.h1' => 'Corre limpio, sé más rápido.',
        'week.title' => 'Esta semana',
        'strip.title' => 'Semana de carreras',
        'lb.title' => 'Mejores vueltas',
        'res.title' => 'Última jornada de carreras',
        'donate.title' => 'Gastos del servidor',
    ]);
});

test('the home page exposes the schedule days with their segments and no fixed format', function () {
    homeTravel('2026-11-04 12:00:00');

    $week = homeProps()['week'];

    expect($week)->not->toHaveKeys(['format', 'cycleCount', 'countdownTarget', 'countdownIsFirstCycle'])
        ->and($week['zone'])->toBe('CET')
        ->and($week['days'])->toHaveCount(7)
        ->and($week['days'][5]['segments'])->toHaveCount(2)
        ->and($week['days'][5]['segments'][1])->toMatchArray([
            'startsAt' => '2026-11-07T18:00:00+01:00',
            'endsAt' => '2026-11-08T00:00:00+01:00',
            'isRace' => true,
            'qualifyingMinutes' => 30,
            'raceType' => 'time',
            'raceMinutes' => 20,
        ]);
});

test('without any configured week the card shows an announcement instead of a track', function () {
    homeTravel('2026-11-04 12:00:00');
    Server::factory()->create(['is_active' => true]);

    $props = homeProps();

    expect($props['week']['configuration'])->toBeNull()
        ->and($props['status']['track'])->toBeNull()
        ->and($props['status']['session'])->toBeNull();
});

test('a week still in voting is not shown as configured', function () {
    homeTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create(['is_active' => true]);
    Week::factory()->for($server)->status(WeekStatus::Voting)->create(['track_id' => Track::factory()->create()->id]);

    expect(homeProps()['week']['configuration'])->toBeNull();
});

test('the configured week exposes track, cars and the track length', function () {
    homeTravel('2026-11-04 12:00:00');
    configuredServer();

    $props = homeProps();

    expect($props['week']['configuration'])->toBe([
        'track' => 'Imola GP',
        'lengthM' => 4909,
        'cars' => ['Ferrari 296 GT3', 'Porsche 911 GT3 R'],
    ])->and($props['status']['track'])->toBe('Imola GP');
});

test('with an empty cache the status is unavailable', function () {
    homeTravel('2026-11-04 12:00:00');
    configuredServer();

    $status = homeProps()['status'];

    expect($status['status'])->toBe('unavailable')
        ->and($status['players'])->toBeNull()
        ->and($status['fetchedAt'])->toBeNull();
});

test('a stale snapshot is unavailable and keeps the time of the last data', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, true, 10, true, $now->subSeconds(46));

    $status = homeProps()['status'];

    expect($status['status'])->toBe('unavailable')
        ->and($status['players'])->toBeNull()
        ->and($status['fetchedAt'])->toBe($now->subSeconds(46)->setTimezone('Europe/Madrid')->format('c'));
});

test('a snapshot of exactly 45 seconds is still fresh', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, true, 10, true, $now->subSeconds(45));

    expect(homeProps()['status']['status'])->toBe('online');
});

test('an unreachable snapshot is unavailable even when recent', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, true, 3, false, $now->subSeconds(5));

    expect(homeProps()['status']['status'])->toBe('unavailable');
});

test('a recent stopped snapshot is offline without players', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, false, 0, true, $now->subSeconds(5));

    $status = homeProps()['status'];

    expect($status['status'])->toBe('offline')
        ->and($status['players'])->toBeNull();
});

test('an online snapshot reports the players and the maximum of the week', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer(maxPlayers: 24, pitSlots: 20);
    app(ServerSnapshot::class)->store($server, true, 14, true, $now->subSeconds(5));

    $status = homeProps()['status'];

    expect($status['status'])->toBe('online')
        ->and($status['players'])->toBe(14)
        ->and($status['maxPlayers'])->toBe(20);
});

test('the maximum falls back to the server value when the week has none', function () {
    homeTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create(['is_active' => true, 'max_players' => 18]);
    Week::factory()->for($server)->status(WeekStatus::Active)->create(['max_players' => null]);

    expect(homeProps()['status']['maxPlayers'])->toBe(18);
});

test('the maximum falls back to the smaller of the server value and the circuit pit slots', function (int $serverMax, int $pitSlots, int $expected) {
    homeTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create(['is_active' => true, 'max_players' => $serverMax]);
    $track = Track::factory()->create(['max_pit_slot' => $pitSlots]);
    Week::factory()->for($server)->status(WeekStatus::Active)->create(['track_id' => $track->id, 'max_players' => null]);

    expect(homeProps()['status']['maxPlayers'])->toBe($expected);
})->with([
    'circuit smaller than the server' => [24, 20, 20],
    'server smaller than the circuit' => [18, 24, 18],
]);

test('the in-game name is the same in every window and the session is practice outside race windows', function (string $moment, ?string $session) {
    homeTravel($moment);
    configuredServer();

    $status = homeProps()['status'];

    expect($status['ingameName'])->toBe('FastGrid | GT3 @ Imola GP | Race Fair, Get Faster')
        ->and($status['session'])->toBe($session);
})->with([
    'weekday' => ['2026-11-04 12:00:00', 'practice'],
    'saturday morning' => ['2026-11-07 10:00:00', 'practice'],
    'saturday race window without an open session' => ['2026-11-07 18:30:00', null],
    'sunday' => ['2026-11-08 10:00:00', 'practice'],
]);

test('the live session comes from the open session of the server while it is online', function (SessionType $type, string $expected) {
    $now = homeTravel('2026-11-07 18:30:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, true, 5, true, $now->subSeconds(5));
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => SessionType::Practice, 'started_at' => $now->subHours(3), 'ended_at' => $now->subHours(2)]);
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => $type, 'started_at' => $now->subMinutes(5), 'ended_at' => null]);

    expect(homeProps()['status']['session'])->toBe($expected);
})->with([
    'practice' => [SessionType::Practice, 'practice'],
    'qualifying' => [SessionType::Qualifying, 'qualy'],
    'race' => [SessionType::Race, 'race'],
]);

test('an open session is ignored when the server is not online', function () {
    $now = homeTravel('2026-11-07 18:30:00');
    $server = configuredServer();
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => SessionType::Race, 'started_at' => $now->subMinutes(5), 'ended_at' => null]);

    expect(homeProps()['status']['session'])->toBeNull();
});

test('the live session is practice in a practice window even when no session is open', function () {
    $now = homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    app(ServerSnapshot::class)->store($server, true, 5, true, $now->subSeconds(5));

    expect(homeProps()['status']['session'])->toBe('practice');
});

test('the in-game name shown on the home page is the custom title as written', function () {
    homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    $server->weeks()->update(['custom_title' => 'fastgrid.club | 911 GT3 @ Donnington | Race Fair - Vote Next Race']);

    expect(homeProps()['status']['ingameName'])->toBe('fastgrid.club | 911 GT3 @ Donnington | Race Fair - Vote Next Race');
});

test('the countdown targets the start of the next race window and compensates with the server clock', function () {
    homeTravel('2026-11-04 12:00:00');

    $props = homeProps();

    expect($props['week']['raceInProgress'])->toBeFalse()
        ->and($props['week']['nextRaceStart'])->toBe('2026-11-07T18:00:00+01:00')
        ->and($props['serverNow'])->toBe('2026-11-04T12:00:00+01:00');
});

test('inside a race window the home page reports races in progress and counts down to the following window', function () {
    homeTravel('2026-11-07 19:15:00');

    $week = homeProps()['week'];

    expect($week['raceInProgress'])->toBeTrue()
        ->and($week['nextRaceStart'])->toBe('2026-11-14T18:00:00+01:00')
        ->and(collect($week['days'][5]['segments'])->pluck('status')->all())->toBe(['done', 'now']);
});

test('after the last race window the countdown targets the first race window of the next week', function () {
    homeTravel('2026-11-08 10:00:00');

    $week = homeProps()['week'];

    expect($week['raceInProgress'])->toBeFalse()
        ->and($week['nextRaceStart'])->toBe('2026-11-14T18:00:00+01:00');
});

test('without any race window this week or next there is no countdown', function () {
    homeTravel('2026-11-04 12:00:00');
    $version = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Madrid')->setTimezone('UTC')]);
    ScheduleSegment::factory()->create(['schedule_version_id' => $version->id, 'weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60]);

    $week = homeProps()['week'];

    expect($week['nextRaceStart'])->toBeNull()
        ->and($week['raceInProgress'])->toBeFalse();
});

test('the zone comes from the start of the week', function () {
    homeTravel('2026-10-21 12:00:00');

    expect(homeProps()['week']['zone'])->toBe('CEST');
});

test('the home page works without any server', function () {
    homeTravel('2026-11-04 12:00:00');

    $status = homeProps()['status'];

    expect($status['status'])->toBe('unavailable')
        ->and($status['ingameName'])->toBeNull()
        ->and($status['maxPlayers'])->toBeNull();
});

test('a failing panel shows the server as unavailable and not offline', function () {
    homeTravel('2026-11-04 12:00:00');
    $server = configuredServer();
    config(['servers.main' => ['url' => 'http://panel.test:8090', 'user' => 'u', 'password' => 'p']]);
    $server->update(['driver' => 'zino_panel', 'credentials_ref' => 'main']);
    zinoFake(['GET /api/server/status' => Http::response(['error' => 'down'], 500)]);

    app(PollServer::class)->handle($server);

    $status = homeProps()['status'];

    expect($status['status'])->toBe('unavailable')
        ->and($status['players'])->toBeNull();
});

test('the home page exposes the best laps and the last race day with 20 rows each', function () {
    $this->seed(DemoStatsSeeder::class);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('bestLaps.rows', 20)
        ->where('bestLaps.rows.0.position', 1)
        ->where('bestLaps.rows.0.gapMs', null)
        ->where('bestLaps.pagination.lastPage', fn ($last) => $last >= 2)
        ->has('bestLaps.options')
        ->has('lastRaceDay.heats')
        ->has('lastRaceDay.rows', 20)
        ->where('lastRaceDay.pagination.pageName', 'res_page')
        ->where('lastRaceDay.pagination.lastPage', fn ($last) => $last >= 2));
});

test('the second best laps page starts at position 21 and the filters are honoured', function () {
    $this->seed(DemoStatsSeeder::class);

    $props = homeProps();
    $trackId = $props['bestLaps']['options']['tracks'][0]['id'];
    $carId = $props['bestLaps']['options']['cars'][0]['id'];

    $this->get(route('home', ['lb_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bestLaps.rows.0.position', 21)
            ->where('bestLaps.pagination.currentPage', 2)
            ->where('bestLaps.selected.trackId', null));

    $this->get(route('home', ['lb_track' => $trackId, 'lb_car' => $carId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bestLaps.selected.trackId', $trackId)
            ->where('bestLaps.selected.carId', $carId)
            ->where('bestLaps.rows.0.gapMs', 0));
});

test('a partial reload of one table does not resolve the other', function () {
    $this->seed(DemoStatsSeeder::class);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('bestLaps', fn (Assert $reload) => $reload
            ->has('bestLaps.rows', 20)
            ->missing('lastRaceDay'))
        ->reloadOnly('lastRaceDay', fn (Assert $reload) => $reload
            ->has('lastRaceDay.rows', 20)
            ->missing('bestLaps')));
});

test('the home response never contains a steam id', function () {
    $this->seed(DemoStatsSeeder::class);

    $content = $this->get(route('home', ['lb_page' => 2, 'res_page' => 2]))->assertOk()->getContent();

    $steamIds = Driver::query()->pluck('steam_id');

    expect($steamIds)->not->toBeEmpty();

    foreach ($steamIds as $steamId) {
        expect($content)->not->toContain((string) $steamId);
    }
});
