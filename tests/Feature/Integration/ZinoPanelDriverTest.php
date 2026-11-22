<?php

declare(strict_types=1);

use App\Enums\RaceDurationType;
use App\Enums\ServerDriverType;
use App\Enums\TrackMode;
use App\Enums\WeatherType;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\ServerCircuitBreaker;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Integration\Server\ZinoPanelDriver;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ZinoPanelHelpers.php';

function zinoPlan(?int $practice = null): ConfigPlan
{
    return new ConfigPlan(TrackMode::Practice, 'Monza|GP|GP Time Attack|5793', ['preset_r8gt2_mech_1', 'preset_695b_mech_1'], 'FastGrid | Semana 3', 20, 600, 1200, RaceDurationType::Time, null, $practice, false, 14, WeatherType::Clear);
}

function zinoChain(Throwable $exception): string
{
    $text = '';

    do {
        $text .= $exception->getMessage().' '.$exception::class.' ';
    } while ($exception = $exception->getPrevious());

    return $text;
}

function assertNoSecrets(string $text): void
{
    expect($text)->not->toContain('Authorization')
        ->not->toContain(ZINO_USER)
        ->not->toContain(ZINO_PASS)
        ->not->toContain('fixture-secret')
        ->not->toContain('Basic ');
}

test('status, live, logs and catalog are mapped from the panel', function () {
    zinoFake();
    $driver = zinoDriver();

    $status = $driver->status();
    $catalog = $driver->catalog();
    $tail = $driver->logTail(5);

    expect($status->running)->toBeTrue()->and($status->state)->toBe('running')->and($status->lastExitCode)->toBeNull()
        ->and($driver->live()->drivers)->toBe([])
        ->and($catalog->cars[0]->carRef)->toBe('preset_695b_mech_1')
        ->and($catalog->cars[0]->displayName)->toBe('Abarth 695 Biposto - Standard')
        ->and($catalog->cars[0]->isMod)->toBeFalse()
        ->and(collect($catalog->cars)->firstWhere('carRef', 'preset_r8gt2_mech_1')->classes)->toBe(['gt2'])
        ->and(collect($catalog->tracks)->pluck('mode')->unique()->values()->all())->toBe([TrackMode::Practice, TrackMode::RaceWeekend])
        ->and($catalog->tracks[0]->trackRef)->toBe('Brands Hatch|GP|GP Time Attack|3916')
        ->and($catalog->tracks[0]->lengthM)->toBe(3916)
        ->and($catalog->tracks[0]->maxPitSlot)->toBe(32)
        ->and($tail->lines)->not->toBeEmpty()->and($tail->lines[array_key_last($tail->lines)])->not->toBe('');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/server/logs?tail=5'));
});

test('log events are mapped from the panel with the cursor and the limit in the query', function () {
    zinoFake();

    $events = zinoDriver()->logEvents(2, 3);

    expect($events->events)->toHaveCount(3)
        ->and($events->events[0]->id)->toBe(3)
        ->and($events->events[2]->id)->toBe(5)
        ->and($events->events[0]->line)->toBeString()->not->toBe('')
        ->and($events->firstId)->toBe(1)
        ->and($events->lastId)->toBeInt();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/server/events?after=2&limit=3'));
});

test('log events without data map to an empty page', function () {
    zinoFake(['GET /api/server/events' => Http::response(['events' => [], 'first_id' => null, 'last_id' => null])]);

    $events = zinoDriver()->logEvents(10, 1000);

    expect($events->events)->toBe([])->and($events->firstId)->toBeNull()->and($events->lastId)->toBeNull();
});

test('a log events failure raises a driver exception', function () {
    zinoFake(['GET /api/server/events' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => zinoDriver()->logEvents(0, 1000))->toThrow(ServerDriverException::class);
});

test('live maps drivers with the internal car name and no ids', function () {
    zinoFake(['GET /api/server/live' => Http::response(zinoFixture('live-with-drivers'))]);

    $live = zinoDriver()->live();

    expect($live->players)->toBe(2)
        ->and($live->drivers)->toHaveCount(2)
        ->and($live->drivers[0]->carRef)->toBe('preset_r8gt2_mech_1')
        ->and($live->drivers[0]->name)->toBe('Piloto Uno')
        ->and($live->drivers[0]->bestLapMs)->toBe(91800)
        ->and($live->drivers[1]->number)->toBeNull()
        ->and($live->drivers[1]->lastLapMs)->toBeNull();
});

test('requests use basic auth against the configured base url', function () {
    zinoFake();

    zinoDriver()->status();

    Http::assertSent(fn (Request $request): bool => $request->url() === ZINO_URL.'/api/server/status'
        && $request->header('Authorization') === ['Basic '.base64_encode(ZINO_USER.':'.ZINO_PASS)]);
});

test('current config maps the form without any password', function () {
    zinoFake();

    $config = zinoDriver()->currentConfig();

    expect($config->mode)->toBe(TrackMode::RaceWeekend)
        ->and($config->trackRef)->toBe('Mount Panorama|GP|GP Race|6213')
        ->and($config->carRefs)->toBe(['preset_e46csl_mech_1'])
        ->and($config->maxPlayers)->toBe(24)
        ->and($config->qualifyingSeconds)->toBe(900)
        ->and($config->raceSeconds)->toBe(900)
        ->and($config->practiceSeconds)->toBeNull()
        ->and($config->cycleEnabled)->toBeTrue()
        ->and(json_encode($config))->not->toContain('fixture-secret');
});

test('apply changes only the planned fields and resends everything else', function () {
    zinoFake();
    $original = zinoFixture('config')['form'];

    $result = zinoDriver()->apply(zinoPlan(300));

    expect($result->ok)->toBeTrue();

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
    $expected['sessions']['practice']['length_sec'] = 300;
    $expected['cars'] = array_map(function (array $car): array {
        $car['is_selected'] = in_array($car['name'], ['preset_r8gt2_mech_1', 'preset_695b_mech_1'], true);

        return $car;
    }, $expected['cars']);

    expect(array_keys($sent))->toBe(['form'])
        ->and($sent['form'])->toEqual($expected)
        ->and($sent['form']['server']['driver_password'])->toBe('fixture-secret-driver_password')
        ->and($sent['form']['server']['admin_password'])->toBe('fixture-secret-admin_password')
        ->and($sent['form']['server']['max_players_limit'])->toBe(35);
});

test('apply sends the practice length to the practice session and current config reads it back', function () {
    zinoFake();
    $driver = zinoDriver();

    $result = $driver->apply(zinoPlan(7200));
    $config = $driver->currentConfig();

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $sent = $request->data();
        }

        return true;
    });

    expect($result->ok)->toBeTrue()
        ->and($sent['form']['sessions']['practice']['length_sec'])->toBe(7200)
        ->and($sent['form']['sessions']['race']['length_sec'])->toBe(1200)
        ->and($config->practiceSeconds)->toBe(7200);
});

test('apply leaves practice untouched when the plan has none and is reflected by current config', function () {
    zinoFake();
    $driver = zinoDriver();

    $driver->apply(zinoPlan());
    $config = $driver->currentConfig();

    expect($config->carRefs)->toBe(['preset_695b_mech_1', 'preset_r8gt2_mech_1'])
        ->and($config->mode)->toBe(TrackMode::Practice)
        ->and($config->practiceSeconds)->toBeNull();
});

test('apply and start report ok false from the panel with http 200', function () {
    zinoFake([
        'POST /api/server/apply' => Http::response(['ok' => false, 'error' => 'secret path /srv fixture-secret-x']),
        'POST /api/server/start' => Http::response(['ok' => false, 'error' => 'run script not found']),
    ]);
    $driver = zinoDriver();

    $apply = $driver->apply(zinoPlan());
    $start = $driver->start();

    expect($apply->ok)->toBeFalse()->and($apply->message)->toBe('zino_panel apply: secret path /srv fixture-secret-x')
        ->and($start->ok)->toBeFalse()->and($start->message)->toBe('zino_panel start: run script not found');
});

test('apply rejected by the panel keeps the panel error text', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false, 'error' => 'track not installed'])]);

    $result = zinoDriver()->apply(zinoPlan());

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('track not installed');
});

test('apply redacts the panel credentials and the form passwords from the panel error text', function () {
    $error = 'bad login '.ZINO_USER.':'.ZINO_PASS.' and fixture-secret-admin_password in /srv';
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false, 'error' => $error])]);

    $result = zinoDriver()->apply(zinoPlan());

    expect($result->message)->toBe('zino_panel apply: bad login [redacted]:[redacted] and [redacted] in /srv');
    assertNoSecrets($result->message);
});

test('start redacts the panel credentials from an http error text', function () {
    zinoFake(['POST /api/server/start' => Http::response(['error' => 'denied for '.ZINO_PASS], 500)]);

    $result = zinoDriver()->start();

    expect($result->message)->toBe('zino_panel start: HTTP 500: denied for [redacted]');
});

test('apply with a panel http error keeps the panel error text', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['error' => 'invalid track'], 500)]);

    $result = zinoDriver()->apply(zinoPlan());

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('invalid track');
});

test('apply rejected by the panel without error keeps the generic message', function () {
    zinoFake(['POST /api/server/apply' => Http::response(['ok' => false])]);

    $result = zinoDriver()->apply(zinoPlan());

    expect($result->ok)->toBeFalse()->and($result->message)->toBe('zino_panel apply: rejected by panel');
});

test('start succeeds when the panel says ok', function () {
    zinoFake();

    expect(zinoDriver()->start()->ok)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/server/start'));
});

test('apply and start never throw on network or http failures and never retry', function (Closure $failure, string $message) {
    $posts = 0;
    zinoFake(['POST /api/server/apply' => function () use ($failure, &$posts) {
        $posts++;

        return $failure();
    }, 'POST /api/server/start' => function () use ($failure, &$posts) {
        $posts++;

        return $failure();
    }]);
    $driver = zinoDriver();

    $apply = $driver->apply(zinoPlan());
    $start = $driver->start();

    expect($apply->ok)->toBeFalse()->and($apply->message)->toBe("zino_panel apply: {$message}")
        ->and($start->ok)->toBeFalse()->and($start->message)->toBe("zino_panel start: {$message}");

    expect($posts)->toBe(2);
    assertNoSecrets($apply->message.$start->message);
})->with([
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out Authorization: Basic abc'), 'timeout'],
    'connection' => [fn () => throw new ConnectionException('cURL error 7: refused'), 'connection failed'],
    'http 500' => [fn () => Http::response('<html>fixture-secret-body</html>', 500), 'HTTP 500'],
]);

test('apply fails cleanly when reading the current config fails', function () {
    zinoFake(['GET /api/config' => Http::response('nope', 500)]);

    $result = zinoDriver()->apply(zinoPlan());

    expect($result->ok)->toBeFalse()->and($result->message)->toBe('zino_panel apply: HTTP 500');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('reads throw a sanitized exception and retry twice on timeouts and 5xx', function (Closure $failure, string $message) {
    $attempts = 0;
    zinoFake(['GET /api/server/status' => function () use ($failure, &$attempts) {
        $attempts++;

        return $failure();
    }]);

    try {
        zinoDriver()->status();
        $this->fail('Expected exception');
    } catch (ServerDriverException $exception) {
        expect($exception->getMessage())->toBe("zino_panel status: {$message}")
            ->and($exception->getPrevious())->toBeNull();
        assertNoSecrets(zinoChain($exception).$exception->getTraceAsString());
    }

    expect($attempts)->toBe(3);
})->with([
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out Authorization: Basic abc'), 'timeout'],
    'http 500' => [fn () => Http::response('{"error":"fixture-secret-driver_password"}', 500), 'HTTP 500'],
]);

test('reads do not retry client errors', function () {
    zinoFake(['GET /api/server/live' => Http::response('denied', 401)]);

    expect(fn () => zinoDriver()->live())->toThrow(ServerDriverException::class, 'zino_panel live: HTTP 401');

    Http::assertSentCount(1);
});

test('reads fail on invalid json', function () {
    zinoFake(['GET /api/server/status' => Http::response('not json', 200)]);

    expect(fn () => zinoDriver()->status())->toThrow(ServerDriverException::class, 'zino_panel status: invalid response');
});

test('the circuit breaker marks the server unreachable after five consecutive failures and recovers', function () {
    $healthy = false;
    zinoFake(['GET /api/server/status' => function () use (&$healthy) {
        return $healthy ? Http::response(zinoFixture('status')) : Http::response('boom', 500);
    }]);
    $server = zinoServer();
    $driver = zinoDriver($server);
    $breaker = new ServerCircuitBreaker;

    for ($i = 0; $i < 4; $i++) {
        try {
            $driver->status();
        } catch (ServerDriverException) {
        }
    }

    expect($breaker->isUnreachable($server))->toBeFalse()->and($breaker->consecutiveFailures($server))->toBe(4);

    try {
        $driver->status();
    } catch (ServerDriverException) {
    }

    expect($breaker->isUnreachable($server))->toBeTrue();

    $healthy = true;
    $driver->status();

    expect($breaker->isUnreachable($server))->toBeFalse()->and($breaker->consecutiveFailures($server))->toBe(0);
});

test('a success in between resets the failure count', function () {
    zinoFake(['GET /api/server/status' => Http::response('boom', 500)]);
    $server = zinoServer();
    $breaker = new ServerCircuitBreaker;

    foreach (range(1, 3) as $ignored) {
        try {
            zinoDriver($server)->status();
        } catch (ServerDriverException) {
        }
    }

    zinoFake();
    zinoDriver($server)->live();

    expect($breaker->consecutiveFailures($server))->toBe(0);
    Cache::flush();
});

test('the manager resolves the zino driver with its config entry', function () {
    $server = zinoServer();

    expect(app(ServerDriverManager::class)->for($server))->toBeInstanceOf(ZinoPanelDriver::class);
});

test('the manager falls back to the server base url', function () {
    config(['servers.main' => ['url' => null, 'user' => 'u', 'password' => 'p']]);
    $server = Server::factory()->create(['driver' => ServerDriverType::ZinoPanel, 'credentials_ref' => 'main', 'base_url' => 'http://fallback.test:8090']);
    zinoFake();

    app(ServerDriverManager::class)->for($server)->status();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://fallback.test:8090/'));
});

test('the manager fails clearly when the config entry or url is missing', function (?string $ref, array $entry) {
    config(['servers' => $entry]);
    $server = Server::factory()->create(['driver' => ServerDriverType::ZinoPanel, 'credentials_ref' => $ref]);

    app(ServerDriverManager::class)->for($server);
})->throws(ServerDriverException::class, 'zino_panel')->with([
    'no ref' => [null, []],
    'no entry' => ['main', []],
    'no url' => ['main', ['main' => ['url' => null, 'user' => 'u', 'password' => 'p']]],
]);

test('apply writes the hour, a fixed time multiplier and the static weather in every session', function () {
    zinoFake();
    $driver = zinoDriver();

    $plan = new ConfigPlan(TrackMode::RaceWeekend, 'Monza|GP|GP Race|5793', ['preset_r8gt2_mech_1'], 'FastGrid | Semana 3', 20, 600, 1200, RaceDurationType::Time, null, 900, true, 9, WeatherType::HeavyRain);
    $result = $driver->apply($plan);

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $sent = $request->data();
        }

        return true;
    });

    expect($result->ok)->toBeTrue()
        ->and($sent['form']['event']['weather'])->toBe('GameModeSelectionWeatherType_HEAVY_RAIN')
        ->and($sent['form']['event']['weather_behaviour'])->toBe('GameModeSelectionWeatherBehaviour_STATIC');

    foreach (['practice', 'qualify', 'warmup', 'race'] as $session) {
        expect($sent['form']['sessions'][$session]['hour'])->toBe(9)
            ->and($sent['form']['sessions'][$session]['minute'])->toBe(0)
            ->and($sent['form']['sessions'][$session]['time_multiplier'])->toBe(1);
    }
});

test('apply writes the laps duration type and laps without touching the race length and current config reads them back', function () {
    zinoFake();
    $driver = zinoDriver();
    $original = zinoFixture('config')['form'];

    $plan = new ConfigPlan(TrackMode::RaceWeekend, 'Monza|GP|GP Race|5793', ['preset_r8gt2_mech_1'], 'FastGrid | Semana 3', 20, 0, null, RaceDurationType::Laps, 12, 900, true, 14, WeatherType::Clear);
    $result = $driver->apply($plan);
    $config = $driver->currentConfig();

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/server/apply')) {
            $sent = $request->data();
        }

        return true;
    });

    expect($result->ok)->toBeTrue()
        ->and($sent['form']['sessions']['race']['duration_type'])->toBe('GameModeSelectionDuration_LAPS')
        ->and($sent['form']['sessions']['race']['laps'])->toBe(12)
        ->and($sent['form']['sessions']['race']['length_sec'])->toBe($original['sessions']['race']['length_sec'])
        ->and($sent['form']['sessions']['qualify']['length_sec'])->toBe(0)
        ->and($sent['form']['sessions']['practice']['length_sec'])->toBe(900)
        ->and($config->raceDurationType)->toBe(RaceDurationType::Laps)
        ->and($config->raceLaps)->toBe(12);
});
