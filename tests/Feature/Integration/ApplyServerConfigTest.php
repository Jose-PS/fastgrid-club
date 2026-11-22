<?php

declare(strict_types=1);

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Servers\ApplyServerConfig;
use App\Enums\ConfigApplicationStatus;
use App\Enums\IntegrationEventType;
use App\Enums\RaceDurationType;
use App\Enums\TrackMode;
use App\Enums\WeatherType;
use App\Integration\Server\Data\ConfigPlan;
use App\Models\Car;
use App\Models\ConfigApplication;
use App\Models\IntegrationEvent;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/ZinoPanelHelpers.php';

const APPLY_CARS = ['preset_695b_mech_1', 'preset_75t_mech_1'];

function applyServer(array $attributes = []): Server
{
    config(['servers.main' => ['url' => ZINO_URL, 'user' => ZINO_USER, 'password' => ZINO_PASS]]);

    return Server::factory()->create($attributes + [
        'driver' => 'zino_panel',
        'credentials_ref' => 'main',
        'ingame_prefix' => 'FastGrid',
        'max_players' => 24,
    ]);
}

function applyTrack(array $attributes = []): Track
{
    return Track::factory()->practice()->create($attributes + ['token' => 'Monza|GP|GP Time Attack|5793', 'max_pit_slot' => 30]);
}

function applyCars(): void
{
    foreach (APPLY_CARS as $name) {
        Car::factory()->create(['internal_name' => $name]);
    }
}

function applyPlan(array $overrides = []): ConfigPlan
{
    $values = $overrides + [
        'mode' => TrackMode::Practice,
        'trackRef' => 'Monza|GP|GP Time Attack|5793',
        'carRefs' => APPLY_CARS,
        'serverName' => 'FastGrid | Semana 3',
        'maxPlayers' => 20,
        'qualifyingSeconds' => 600,
        'raceSeconds' => 1200,
        'raceDurationType' => RaceDurationType::Time,
        'raceLaps' => null,
        'practiceSeconds' => null,
        'cycleEnabled' => false,
        'startHour' => 14,
        'weather' => WeatherType::Clear,
    ];

    return new ConfigPlan(...$values);
}

function applyAction(): ApplyServerConfig
{
    return app(ApplyServerConfig::class);
}

function assertNoApplyLeak(string $text): void
{
    expect($text)->not->toContain('Authorization')
        ->not->toContain(ZINO_USER)
        ->not->toContain(ZINO_PASS)
        ->not->toContain('fixture-secret')
        ->not->toContain('Basic ');
}

function assertNoApplySecrets(): void
{
    foreach (ConfigApplication::query()->get() as $application) {
        assertNoApplyLeak(json_encode($application->getAttributes(), JSON_THROW_ON_ERROR));
    }

    foreach (IntegrationEvent::query()->get() as $event) {
        assertNoApplyLeak($event->summary);
    }

    assertNoApplyLeak(json_encode(DB::table('cache')->get(), JSON_THROW_ON_ERROR));
}

beforeEach(function () {
    config(['cache.default' => 'database']);
    applyCars();
    applyTrack();
});

test('applies the plan to the panel resending the form with the passwords untouched and records an ok application', function () {
    zinoFake();
    $server = applyServer();
    $original = zinoFixture('config')['form'];

    $application = applyAction()->handle($server, applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Ok)
        ->and($application->attempt)->toBe(1)
        ->and($application->error)->toBeNull()
        ->and($application->finished_at)->not->toBeNull()
        ->and($application->snapshot['max_players'])->toBe(20);

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $sent = $request->data();
        }

        return true;
    });

    $expected = $original;
    $expected['server']['server_name'] = 'FastGrid | Semana 3';
    $expected['server']['max_players'] = 20;
    $expected['server']['cycle_enabled'] = false;
    $expected['event']['type'] = 'GameModeType_PRACTICE';
    $expected['event']['track'] = 'Monza|GP|GP Time Attack|5793';
    $expected['sessions']['qualify']['length_sec'] = 600;
    $expected['sessions']['race']['length_sec'] = 1200;
    $expected['sessions']['race']['duration_type'] = 'GameModeSelectionDuration_TIME';
    $expected['cars'] = array_map(function (array $car): array {
        $car['is_selected'] = in_array($car['name'], APPLY_CARS, true);

        return $car;
    }, $expected['cars']);

    expect($sent['form'])->toEqual($expected)
        ->and($sent['form']['server']['driver_password'])->toBe('fixture-secret-driver_password')
        ->and($sent['form']['server']['admin_password'])->toBe('fixture-secret-admin_password');
});

test('passwords never reach the database, the cache, the logs or exception messages', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false, 'error' => 'fixture-secret-driver_password'])]);
    Log::spy();
    $server = applyServer();

    $failed = applyAction()->handle($server, applyPlan());
    Http::swap(new HttpFactory);
    zinoFake();
    $ok = applyAction()->handle($server, applyPlan());

    expect($failed->status)->toBe(ConfigApplicationStatus::Failed)->and($ok->status)->toBe(ConfigApplicationStatus::Ok);

    assertNoApplySecrets();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    expect(json_encode($ok->snapshot))->not->toContain('password');
});

test('max players is clamped by the track pit slots and by the server limit', function () {
    zinoFake();
    Track::query()->update(['max_pit_slot' => 12]);

    $byTrack = applyAction()->handle(applyServer(), applyPlan(['maxPlayers' => 30]));

    Track::query()->update(['max_pit_slot' => 30]);
    $byServer = applyAction()->handle(applyServer(['max_players' => 16]), applyPlan(['maxPlayers' => 30]));
    $byPlan = applyAction()->handle(applyServer(), applyPlan(['maxPlayers' => 10]));

    expect($byTrack->snapshot['max_players'])->toBe(12)->and($byTrack->status)->toBe(ConfigApplicationStatus::Ok)
        ->and($byServer->snapshot['max_players'])->toBe(16)
        ->and($byPlan->snapshot['max_players'])->toBe(10);
});

test('the in-game prefix is compared without case and accepts any following character', function (string $name) {
    zinoFake();

    $application = applyAction()->handle(applyServer(), applyPlan(['serverName' => $name]));

    expect($application->status)->toBe(ConfigApplicationStatus::Ok);
})->with([
    'lowercase with dot' => ['fastgrid.club | 911 GT3 @ Donnington | Race Fair - Vote Next Race'],
    'uppercase' => ['FASTGRID | Semana 3'],
]);

test('rejects invalid plans without calling the panel', function (Closure $arrange, array $overrides) {
    Http::fake();
    Http::preventStrayRequests();
    $arrange();

    $application = applyAction()->handle(applyServer(), applyPlan($overrides));

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toStartWith('invalid plan')
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::ApplyFailed)->count())->toBe(1);
    Http::assertNothingSent();
})->with([
    'name without prefix' => [fn () => null, ['serverName' => 'Otro | Semana 3']],
    'no cars' => [fn () => null, ['carRefs' => []]],
    'inactive car' => [fn () => Car::query()->where('internal_name', 'preset_75t_mech_1')->update(['active' => false]), []],
    'unknown car' => [fn () => null, ['carRefs' => ['preset_695b_mech_1', 'missing']]],
    'inactive track' => [fn () => Track::query()->update(['active' => false]), []],
    'unknown track' => [fn () => null, ['trackRef' => 'Nowhere|GP|GP|1']],
    'wrong mode' => [fn () => null, ['mode' => TrackMode::RaceWeekend]],
]);

test('fails when the re-read config does not match, listing only field names', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => true, 'restarted' => false])]);

    $application = applyAction()->handle(applyServer(), applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toStartWith('verification failed, fields differ: ')
        ->and($application->error)->toContain('serverName', 'trackRef', 'carRefs')
        ->and($application->error)->not->toContain('FastGrid');
    assertNoApplySecrets();
});

test('practice verification ignores the qualifying and race durations the panel zeroes out', function () {
    $stored = null;
    zinoFake([
        'POST /api/server/apply' => function (Request $request) use (&$stored) {
            $stored = $request->data()['form'];
            $stored['sessions']['qualify']['length_sec'] = 0;
            $stored['sessions']['race']['length_sec'] = 0;

            return Http::response(['ok' => true, 'restarted' => false]);
        },
        'GET /api/config' => function () use (&$stored) {
            return Http::response(['form' => $stored ?? zinoFixture('config')['form']]);
        },
    ]);

    $application = applyAction()->handle(applyServer(), applyPlan(['qualifyingSeconds' => 1800, 'raceSeconds' => 1200]));

    expect($application->status)->toBe(ConfigApplicationStatus::Ok)
        ->and($application->error)->toBeNull();
});

test('practice verification passes when the panel keeps the practice length of the plan', function () {
    zinoFake();

    $application = applyAction()->handle(applyServer(), applyPlan(['practiceSeconds' => 7200]));

    expect($application->status)->toBe(ConfigApplicationStatus::Ok)
        ->and($application->error)->toBeNull();
});

test('practice verification fails when the panel zeroes the practice length', function () {
    $stored = null;
    zinoFake([
        'POST /api/server/apply' => function (Request $request) use (&$stored) {
            $stored = $request->data()['form'];
            $stored['sessions']['practice']['length_sec'] = 0;

            return Http::response(['ok' => true, 'restarted' => false]);
        },
        'GET /api/config' => function () use (&$stored) {
            return Http::response(['form' => $stored ?? zinoFixture('config')['form']]);
        },
    ]);

    $application = applyAction()->handle(applyServer(), applyPlan(['practiceSeconds' => 7200]));

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toBe('verification failed, fields differ: practiceSeconds');
});

test('race weekend verification still compares the qualifying and race durations', function () {
    Track::query()->update(['mode' => TrackMode::RaceWeekend]);
    $stored = null;
    zinoFake([
        'POST /api/server/apply' => function (Request $request) use (&$stored) {
            $stored = $request->data()['form'];
            $stored['sessions']['qualify']['length_sec'] = 0;
            $stored['sessions']['race']['length_sec'] = 0;

            return Http::response(['ok' => true, 'restarted' => false]);
        },
        'GET /api/config' => function () use (&$stored) {
            return Http::response(['form' => $stored ?? zinoFixture('config')['form']]);
        },
    ]);

    $application = applyAction()->handle(applyServer(), applyPlan(['mode' => TrackMode::RaceWeekend, 'qualifyingSeconds' => 1800, 'raceSeconds' => 1200]));

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toContain('qualifyingSeconds', 'raceSeconds');
});

test('starts the server when it is stopped after applying', function () {
    zinoFake(['GET /api/server/status' => Http::response(['running' => false, 'state' => 'stopped', 'last_exit_code' => 0])]);

    $application = applyAction()->handle(applyServer(), applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Ok);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/server/start'));
});

test('does not start the server when it is already running', function () {
    zinoFake();

    applyAction()->handle(applyServer(), applyPlan());

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/server/start'));
});

test('fails when starting the stopped server fails', function () {
    zinoFake([
        'GET /api/server/status' => Http::response(['running' => false, 'state' => 'stopped']),
        'POST /api/server/start' => Http::response(['ok' => false]),
    ]);

    $application = applyAction()->handle(applyServer(), applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toBe('zino_panel start: rejected by panel');
});

test('a rejected apply with http 200 fails and records an event, and a later success resets the counter', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false, 'error' => 'internal path /srv'])]);
    $server = applyServer();

    $failed = applyAction()->handle($server, applyPlan());

    $event = IntegrationEvent::query()->where('type', IntegrationEventType::ApplyFailed)->sole();
    expect($failed->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($failed->error)->toBe('zino_panel apply: internal path /srv')
        ->and($event->summary)->toBe('zino_panel apply: internal path /srv')
        ->and(Cache::get(IntegrationEventType::ApplyFailed->failuresKey($server)))->toBe(1);

    Http::swap(new HttpFactory);
    zinoFake();
    $ok = applyAction()->handle($server, applyPlan());

    expect($ok->status)->toBe(ConfigApplicationStatus::Ok)
        ->and(Cache::get(IntegrationEventType::ApplyFailed->failuresKey($server)))->toBeNull()
        ->and(RecordIntegrationFailure::THRESHOLD)->toBeGreaterThan(1);
});

test('a missing driver configuration fails the application instead of throwing', function () {
    $server = Server::factory()->create(['driver' => 'zino_panel', 'credentials_ref' => 'absent', 'ingame_prefix' => 'FastGrid']);

    $application = applyAction()->handle($server, applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)->and($application->error)->toContain('config/servers.php');
});

test('attempt increments per server and block key and stores week and block', function () {
    zinoFake();
    $server = applyServer();
    $other = applyServer();
    $week = Week::factory()->create(['server_id' => $server->id]);

    $first = applyAction()->handle($server, applyPlan(), $week, 'w1:race_day', TrackMode::RaceWeekend);
    $second = applyAction()->handle($server, applyPlan(), $week, 'w1:race_day', TrackMode::RaceWeekend);
    $otherKey = applyAction()->handle($server, applyPlan(), $week, 'w1:practice_sunday', TrackMode::Practice);
    $otherServer = applyAction()->handle($other, applyPlan(), null, 'w1:race_day');
    $noKey = applyAction()->handle($server, applyPlan());
    $noKeyAgain = applyAction()->handle($server, applyPlan());

    expect($first->attempt)->toBe(1)->and($second->attempt)->toBe(2)
        ->and($otherKey->attempt)->toBe(1)
        ->and($otherServer->attempt)->toBe(1)
        ->and($noKey->attempt)->toBe(1)->and($noKeyAgain->attempt)->toBe(1)
        ->and($first->week_id)->toBe($week->id)
        ->and($first->block)->toBe('race_weekend')
        ->and($first->fresh()->block_key)->toBe('w1:race_day');
});

test('the fake driver applies and verifies a plan end to end', function () {
    $server = Server::factory()->create(['ingame_prefix' => 'FastGrid']);

    $application = applyAction()->handle($server, applyPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Ok);
});

function applyLapsPlan(int $laps = 10): ConfigPlan
{
    Track::query()->update(['mode' => TrackMode::RaceWeekend]);

    return applyPlan([
        'mode' => TrackMode::RaceWeekend,
        'qualifyingSeconds' => 0,
        'raceSeconds' => null,
        'raceDurationType' => RaceDurationType::Laps,
        'raceLaps' => $laps,
        'practiceSeconds' => 900,
        'cycleEnabled' => true,
    ]);
}

test('a race by laps verifies against the panel duration type and laps', function () {
    zinoFake();

    $application = applyAction()->handle(applyServer(), applyLapsPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Ok)
        ->and($application->error)->toBeNull()
        ->and($application->snapshot['race_duration_type'])->toBe('laps')
        ->and($application->snapshot['race_laps'])->toBe(10)
        ->and($application->snapshot['race_seconds'])->toBeNull();
});

test('a race by laps fails verification when the panel returns other laps', function () {
    $stored = null;
    zinoFake([
        'POST /api/server/apply' => function (Request $request) use (&$stored) {
            $stored = $request->data()['form'];
            $stored['sessions']['race']['laps'] = 5;

            return Http::response(['ok' => true, 'restarted' => false]);
        },
        'GET /api/config' => function () use (&$stored) {
            return Http::response(['form' => $stored ?? zinoFixture('config')['form']]);
        },
    ]);

    $application = applyAction()->handle(applyServer(), applyLapsPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toBe('verification failed, fields differ: raceLaps');
});

test('a race by laps fails verification when the panel keeps a timed race', function () {
    $stored = null;
    zinoFake([
        'POST /api/server/apply' => function (Request $request) use (&$stored) {
            $stored = $request->data()['form'];
            $stored['sessions']['race']['duration_type'] = 'GameModeSelectionDuration_TIME';

            return Http::response(['ok' => true, 'restarted' => false]);
        },
        'GET /api/config' => function () use (&$stored) {
            return Http::response(['form' => $stored ?? zinoFixture('config')['form']]);
        },
    ]);

    $application = applyAction()->handle(applyServer(), applyLapsPlan());

    expect($application->status)->toBe(ConfigApplicationStatus::Failed)
        ->and($application->error)->toBe('verification failed, fields differ: raceDurationType');
});
